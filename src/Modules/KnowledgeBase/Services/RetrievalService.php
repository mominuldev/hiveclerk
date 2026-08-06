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
use Hiveclerk\Domain\Knowledge\RetrievalServiceInterface;
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
final class RetrievalService implements RetrievalServiceInterface {

	/**
	 * Keyword results fetched for fusion.
	 *
	 * Matched to the coarse pass's candidate count so neither signal is
	 * structurally short-changed in the fusion.
	 */
	private const KEYWORD_LIMIT = 200;

	/**
	 * Shortest word InnoDB's full-text index stores.
	 *
	 * `innodb_ft_min_token_size`, whose default is 3. Hard-coded rather
	 * than read from the server on every query: it cannot change without a
	 * restart, and a `SHOW VARIABLES` round trip on the retrieval path to
	 * confirm a value that is the default on every host this ships to
	 * would cost more than the note it produces is worth. A server that
	 * has lowered it makes this warning occasionally over-cautious, which
	 * is the harmless direction to be wrong in.
	 */
	private const MIN_KEYWORD_TOKEN = 3;

	/**
	 * What a keyword rank is worth against a vector rank.
	 *
	 * ## Why this is not 1.0
	 *
	 * Equal weighting was measured and it costs recall. Over the 54-question
	 * evaluation set, unweighted fusion scored 0.815 recall@5 and vector
	 * search alone scored 0.870: of the ten questions fusion missed, six came
	 * back with the keyword arm switched off entirely.
	 *
	 * The failure is one-directional and worth stating precisely, because it
	 * is not what hybrid search is supposed to do. RRF only sees orderings,
	 * so a chunk that ranks first on keyword contributes exactly as much as
	 * one that ranks first on cosine — however poor its semantic match
	 * actually is. Long documents are full of common words and MySQL's
	 * FULLTEXT rewards them, so the chunks that win on keyword are
	 * systematically the ones that deserve it least. "What margin does a
	 * stockist make?" retrieves the right page first at cosine 0.81 on the
	 * vector arm; unweighted fusion promoted three chunks of our own
	 * monetisation deliverable at cosine 0.69–0.70 and pushed the answer out
	 * of the top five.
	 *
	 * Weighting keeps the thing keyword is genuinely for — a part number, an
	 * SKU, an error code, anything where the exact string is the answer and
	 * the embedding has nothing to grip — while stopping a lexical
	 * coincidence from displacing a strong semantic match. Keyword can add;
	 * it can no longer outvote.
	 *
	 * ## How the value was chosen
	 *
	 * Swept over a two-thirds sample of the question set and confirmed
	 * against the held-out third, rather than fitted to all 54 — a retrieval
	 * constant tuned on the data it is then reported against is a number that
	 * improves without the product improving. The split is every third
	 * question rather than a contiguous slice, because the set is grouped by
	 * page and a contiguous split measures generalisation across subject
	 * matter instead of across questions.
	 *
	 * On the held-out third, recall@5 went from 0.833 to 0.889 and MRR from
	 * 0.727 to 0.819. Across the whole set, 0.815 to 0.889 and 0.698 to
	 * 0.765 — better than unweighted fusion *and* better than vector alone
	 * (0.870 / 0.615) on both measures, which is the outcome hybrid search
	 * is supposed to produce and previously did not.
	 *
	 * The sweep that chose this value ran while every query was embedded
	 * with the document task type — the defect EmbeddingTask fixed — so
	 * both arms were measured against weaker vectors than the ones now in
	 * play. The configuration clears the M1 floor with the fix in place
	 * (1.000 on the structured corpus, 0.944 flat), but the constant has
	 * not been re-swept against correctly-embedded queries and a re-sweep
	 * might land somewhere else.
	 */
	private const KEYWORD_WEIGHT = 0.2;

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

			$this->noteUnsearchableTerms( $query, $diagnostics );
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
	 * Say so when the keyword arm could not see part of the question.
	 *
	 * InnoDB's full-text index does not store tokens shorter than
	 * `innodb_ft_min_token_size`, which is three characters by default and
	 * is a server variable a plugin cannot change: raising it needs a
	 * restart and a rebuild of every full-text index on the server.
	 *
	 * The awkward part is what that excludes. This arm exists precisely to
	 * catch the part numbers, SKUs and error codes an embedding has
	 * nothing to grip on — and a two-character one is exactly the kind of
	 * term that is invisible here. Measured on MySQL 9.3: `warranty`
	 * matches, `AI` returns nothing at all.
	 *
	 * Nothing can be done about it from inside the product, so the next
	 * best thing is that the person testing a query and wondering why it
	 * found nothing is told, rather than left to conclude that retrieval
	 * is broken.
	 *
	 * @param string               $query       The visitor's question.
	 * @param RetrievalDiagnostics $diagnostics Diagnostics.
	 * @return void
	 */
	private function noteUnsearchableTerms( string $query, RetrievalDiagnostics $diagnostics ): void {
		$terms = preg_split( '/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $terms ) ) {
			return;
		}

		$short = array();

		foreach ( $terms as $term ) {
			$length = mb_strlen( $term );

			if ( $length > 0 && $length < self::MIN_KEYWORD_TOKEN ) {
				$short[ mb_strtolower( $term ) ] = true;
			}
		}

		if ( array() === $short ) {
			return;
		}

		$diagnostics->note(
			sprintf(
				'Keyword search ignored %s: the database does not index words shorter than '
					. '%d characters, so short codes and abbreviations are found by meaning '
					. 'or not at all.',
				implode( ', ', array_map( static fn ( string $t ): string => '"' . $t . '"', array_keys( $short ) ) ),
				self::MIN_KEYWORD_TOKEN
			)
		);
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

		/**
		 * Filter the weight the keyword arm carries in fusion.
		 *
		 * The vector arm is always 1.0. A site whose content is mostly
		 * part numbers, SKUs or error codes — where the exact string is
		 * the answer and the embedding has little to work with — can raise
		 * this; a site of ordinary prose should not need to.
		 *
		 * @param float $weight Keyword weight, relative to the vector arm.
		 */
		$keywordWeight = (float) apply_filters( 'hiveclerk/retrieval/keyword_weight', self::KEYWORD_WEIGHT );

		$fused = ReciprocalRankFusion::fuse(
			array( $vectorIds, $keywordIds ),
			array( 1.0, max( 0.0, $keywordWeight ) )
		);

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
