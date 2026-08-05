<?php
/**
 * Finds the chunks that answer a question.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Services;

use Hiveclerk\Ai\EmbeddingModel;
use Hiveclerk\Ai\ProviderException;
use Hiveclerk\Domain\Knowledge\Chunk;
use Hiveclerk\Domain\Knowledge\ChunkRepositoryInterface;
use Hiveclerk\Domain\Knowledge\DocumentRepositoryInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
use Hiveclerk\Domain\Knowledge\RetrievalDiagnostics;
use Hiveclerk\Domain\Knowledge\RetrievalOptions;
use Hiveclerk\Domain\Knowledge\RetrievalResult;
use Hiveclerk\Domain\Knowledge\RetrievedChunk;
use Hiveclerk\Domain\Knowledge\ScoredChunk;
use Hiveclerk\Domain\Knowledge\VectorStoreInterface;
use Hiveclerk\Modules\KnowledgeBase\Vector\ReciprocalRankFusion;

/**
 * Stage 3 — fusion, and the front door to retrieval.
 *
 * ## Why two signals rather than one
 *
 * Vectors and keywords fail in opposite directions and each covers the
 * other. An embedding is a summary of meaning: it finds the returns page
 * for "can I send this back" and is reliably vague about "SKU AX-4471",
 * because an exact token carries little meaning to summarise. A keyword
 * index is the reverse — precise about the token, blind to the paraphrase.
 * Running only one of them produces a product that is inexplicably good
 * at some questions and inexplicably bad at others.
 *
 * ## Why fusion combines ranks, not scores
 *
 * A cosine similarity is bounded to [-1, 1]; MySQL's FULLTEXT relevance
 * is an unbounded BM25 figure whose scale depends on the corpus. Adding
 * or averaging them requires inventing a normalisation, and every choice
 * of normalisation is a hidden weighting that changes with corpus size.
 * Reciprocal rank fusion sidesteps it: only the *ordering* each signal
 * produced is used, so the two never have to be made commensurable.
 *
 * ## Why the threshold is applied to the cosine
 *
 * The fused score answers "which of these should be first". It does not
 * answer "is any of this actually about the question" — a chunk ranked
 * first by both signals fuses to a high score even when both signals
 * thought it was a poor match. The number that answers the second
 * question is the cosine similarity, and that is what the confidence
 * threshold gates. The knowledge-gaps report depends on this being right:
 * its whole purpose is spotting questions where the best match was weak.
 */
final class RetrievalService {

	/**
	 * Keyword results fetched for fusion.
	 *
	 * Matched to the coarse pass's candidate count so neither signal is
	 * structurally short-changed in the fusion.
	 */
	private const KEYWORD_LIMIT = 200;

	/**
	 * Construct.
	 *
	 * @param EmbeddingService                   $embeddings Query embedding.
	 * @param VectorStoreInterface               $vectors    Vector search.
	 * @param ChunkRepositoryInterface           $chunks     Chunk storage.
	 * @param DocumentRepositoryInterface        $documents  Document storage.
	 * @param KnowledgeSourceRepositoryInterface $sources    Source storage.
	 */
	public function __construct(
		private readonly EmbeddingService $embeddings,
		private readonly VectorStoreInterface $vectors,
		private readonly ChunkRepositoryInterface $chunks,
		private readonly DocumentRepositoryInterface $documents,
		private readonly KnowledgeSourceRepositoryInterface $sources
	) {
	}

	/**
	 * Retrieve the chunks most likely to answer a question.
	 *
	 * @param string           $query     The question.
	 * @param array<int, int>  $sourceIds Sources to search.
	 * @param RetrievalOptions $options   Parameters.
	 * @return RetrievalResult
	 */
	public function retrieve( string $query, array $sourceIds, RetrievalOptions $options ): RetrievalResult {
		$started     = microtime( true );
		$diagnostics = new RetrievalDiagnostics();
		$query       = trim( $query );
		$sourceIds   = array_values( array_unique( array_filter( array_map( 'intval', $sourceIds ) ) ) );

		if ( '' === $query || array() === $sourceIds ) {
			return new RetrievalResult( array(), $diagnostics );
		}

		$pin = $this->pinFor( $sourceIds );

		if ( null === $pin ) {
			$diagnostics->note(
				'No embedding provider is configured, so nothing can be searched by meaning. '
				. 'Add a provider key under Settings.'
			);

			return new RetrievalResult( array(), $diagnostics );
		}

		$vectorRanked = array();

		try {
			$embedStarted = microtime( true );
			$vector       = $this->embeddings->embedQuery( $query, $pin, $options->useCache );

			$diagnostics->embedMs = ( microtime( true ) - $embedStarted ) * 1000;

			$vectorRanked = $this->vectors->search(
				$vector,
				$sourceIds,
				$options->candidates,
				$options,
				$diagnostics
			);
		} catch ( ProviderException $e ) {
			// A provider outage degrades retrieval to keyword-only rather
			// than failing the visitor's message. An answer found by
			// keyword alone is worse than one found by both; no answer at
			// all is worse than either.
			$diagnostics->note( 'Embedding unavailable, keyword search only: ' . $e->getMessage() );
			$diagnostics->strategy = 'keyword_only';
		}

		$keywordRanked = array();

		if ( $options->useKeyword ) {
			$keywordStarted = microtime( true );
			$keywordRanked  = $this->chunks->searchKeyword( $query, $sourceIds, self::KEYWORD_LIMIT );

			$diagnostics->keywordMs      = ( microtime( true ) - $keywordStarted ) * 1000;
			$diagnostics->keywordMatches = count( $keywordRanked );
		}

		$fusionStarted = microtime( true );

		$results = $this->fuse( $vectorRanked, $keywordRanked, $options );

		$diagnostics->fusionMs  = ( microtime( true ) - $fusionStarted ) * 1000;
		$diagnostics->returned  = count( $results );
		$diagnostics->peakBytes = memory_get_peak_usage( true );
		$diagnostics->totalMs   = ( microtime( true ) - $started ) * 1000;

		foreach ( $results as $result ) {
			if ( $result->isConfident( $options->threshold ) ) {
				++$diagnostics->confident;
			}
		}

		/**
		 * Filter the chunks retrieval will hand to a clerk.
		 *
		 * The documented extension point for reordering, filtering by
		 * metadata or injecting a result from another store.
		 *
		 * @param array<int, RetrievedChunk> $results   Ordered results.
		 * @param string                     $query     The question.
		 * @param array<int, int>            $sourceIds Sources searched.
		 */
		$filtered = apply_filters( 'hiveclerk/retrieval/results', $results, $query, $sourceIds );

		if ( ! is_array( $filtered ) ) {
			return new RetrievalResult( $results, $diagnostics );
		}

		$valid = array_values(
			array_filter( $filtered, static fn ( $item ): bool => $item instanceof RetrievedChunk )
		);

		return new RetrievalResult( $valid, $diagnostics );
	}

	/**
	 * Which embedding model a source set was indexed with.
	 *
	 * The pin is read from the sources rather than from settings. Settings
	 * say what the *next* index run will use; the vectors on disk were
	 * produced by whatever was configured when they were written, and
	 * searching them with anything else compares vectors from unrelated
	 * spaces.
	 *
	 * @param array<int, int> $sourceIds Sources.
	 * @return EmbeddingModel|null
	 */
	public function pinFor( array $sourceIds ): ?EmbeddingModel {
		foreach ( $sourceIds as $sourceId ) {
			$source = $this->sources->find( (int) $sourceId );

			if ( null === $source ) {
				continue;
			}

			$pin = EmbeddingModel::fromStorage(
				$source->embedProvider,
				$source->embedModel,
				$source->embedDimensions
			);

			if ( null !== $pin ) {
				return $pin;
			}
		}

		// Nothing indexed yet: fall back to what the next run would use, so
		// a playground query against an empty source explains itself rather
		// than reporting a missing provider.
		return $this->embeddings->configured();
	}

	/**
	 * Combine two ranked lists into one.
	 *
	 * @param array<int, ScoredChunk>                        $vectorRanked  Vector results.
	 * @param array<int, array{chunk_id: int, score: float}> $keywordRanked Keyword results.
	 * @param RetrievalOptions                               $options       Parameters.
	 * @return array<int, RetrievedChunk>
	 */
	private function fuse( array $vectorRanked, array $keywordRanked, RetrievalOptions $options ): array {
		$vectorIds    = array();
		$vectorScores = array();

		foreach ( $vectorRanked as $scored ) {
			$vectorIds[]                      = $scored->chunkId;
			$vectorScores[ $scored->chunkId ] = $scored->score;
		}

		$keywordIds = array();
		$bm25       = array();

		foreach ( $keywordRanked as $match ) {
			$keywordIds[]               = $match['chunk_id'];
			$bm25[ $match['chunk_id'] ] = $match['score'];
		}

		$fused = ReciprocalRankFusion::fuse( array( $vectorIds, $keywordIds ) );

		if ( array() === $fused ) {
			return array();
		}

		$vectorRanks  = ReciprocalRankFusion::ranks( $vectorIds );
		$keywordRanks = ReciprocalRankFusion::ranks( $keywordIds );

		// Text is loaded for the top slice only. Fusion routinely produces
		// three or four hundred candidate ids and returns five of them;
		// hydrating all of them would read most of the chunks table to
		// throw almost all of it away.
		$top    = array_slice( $fused, 0, $options->topK, true );
		$chunks = $this->hydrate( array_keys( $top ) );
		$titles = $this->titlesFor( $chunks );

		$results = array();
		$rank    = 0;

		foreach ( $top as $chunkId => $score ) {
			if ( ! isset( $chunks[ $chunkId ] ) ) {
				// The chunk was deleted between being ranked and being
				// read — a re-index finishing mid-query. Skipping it is
				// correct: it no longer exists to be cited.
				continue;
			}

			$chunk    = $chunks[ $chunkId ];
			$document = $titles[ $chunk->documentId ] ?? array(
				'title' => '',
				'url'   => null,
			);

			++$rank;

			$results[] = new RetrievedChunk(
				chunk: $chunk,
				vectorScore: $vectorScores[ $chunkId ] ?? 0.0,
				bm25Score: $bm25[ $chunkId ] ?? 0.0,
				fusedScore: $score,
				rank: $rank,
				vectorRank: $vectorRanks[ $chunkId ] ?? null,
				keywordRank: $keywordRanks[ $chunkId ] ?? null,
				documentTitle: $document['title'],
				documentUrl: $document['url']
			);
		}

		return $results;
	}

	/**
	 * Load chunks by id, keyed by id.
	 *
	 * @param array<int, int> $chunkIds Chunk ids.
	 * @return array<int, Chunk>
	 */
	private function hydrate( array $chunkIds ): array {
		$loaded = array();

		foreach ( $this->chunks->findMany( $chunkIds ) as $chunk ) {
			if ( null !== $chunk->id ) {
				$loaded[ $chunk->id ] = $chunk;
			}
		}

		return $loaded;
	}

	/**
	 * Titles for the documents these chunks came from.
	 *
	 * @param array<int, Chunk> $chunks Chunks.
	 * @return array<int, array{title: string, url: string|null}>
	 */
	private function titlesFor( array $chunks ): array {
		$documentIds = array();

		foreach ( $chunks as $chunk ) {
			$documentIds[ $chunk->documentId ] = true;
		}

		return $this->documents->titles( array_keys( $documentIds ) );
	}
}
