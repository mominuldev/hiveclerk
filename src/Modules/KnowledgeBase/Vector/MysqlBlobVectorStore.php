<?php
/**
 * Two-stage vector search over MySQL BLOBs.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Vector;

use Hiveclerk\Domain\Knowledge\Embedding;
use Hiveclerk\Domain\Knowledge\EmbeddingMatrix;
use Hiveclerk\Domain\Knowledge\EmbeddingRepositoryInterface;
use Hiveclerk\Domain\Knowledge\RetrievalDiagnostics;
use Hiveclerk\Domain\Knowledge\RetrievalOptions;
use Hiveclerk\Domain\Knowledge\ScoredChunk;
use Hiveclerk\Domain\Knowledge\VectorStoreInterface;

/**
 * The self-hosted binding of TD-1.
 *
 * MySQL 9's native `VECTOR` type does not exist on the hosting this
 * product targets, an external vector database would break the promise
 * that a customer's content never leaves their server, and a naive
 * float32 full scan runs out of both memory and time somewhere around
 * five thousand chunks. What is left is doing the work in two passes:
 *
 * **Stage 1 — coarse.** Every vector in the source set, quantised to one
 * bit per dimension, held as a single 1.9 MB string and compared by
 * Hamming distance. Cheap enough to run over the whole corpus.
 *
 * **Stage 2 — exact.** The two hundred survivors have their real float32
 * vectors loaded and scored by cosine similarity. Only the coarse pass
 * runs at corpus scale; only the exact pass decides the ordering.
 *
 * Below a few hundred chunks the machinery costs more than it saves, and
 * the store scans exactly in one pass instead. That is not a special
 * case bolted on — it is the bottom rung of the scaling ladder in the
 * architecture, and the top rung is replacing this class entirely.
 */
final class MysqlBlobVectorStore implements VectorStoreInterface {

	/**
	 * Construct.
	 *
	 * @param EmbeddingRepositoryInterface $embeddings Persistence.
	 * @param MatrixCache                  $cache      Matrix cache.
	 */
	public function __construct(
		private readonly EmbeddingRepositoryInterface $embeddings,
		private readonly MatrixCache $cache
	) {
	}

	/**
	 * Store vectors.
	 *
	 * @param array<int, \Hiveclerk\Domain\Knowledge\StoredEmbedding> $embeddings Rows.
	 * @return int
	 */
	public function upsertMany( array $embeddings ): int {
		if ( array() === $embeddings ) {
			return 0;
		}

		$written = $this->embeddings->saveMany( $embeddings );

		$sourceIds = array();

		foreach ( $embeddings as $embedding ) {
			$sourceIds[ $embedding->sourceId ] = true;
		}

		// Invalidated after the write, not before. A concurrent search
		// between a pre-emptive flush and the commit would rebuild the
		// matrix from the old rows and cache that as current.
		$this->invalidate( array_keys( $sourceIds ) );

		return $written;
	}

	/**
	 * Remove vectors.
	 *
	 * @param array<int, int> $chunkIds Chunks.
	 * @return void
	 */
	public function delete( array $chunkIds ): void {
		if ( array() === $chunkIds ) {
			return;
		}

		$this->embeddings->deleteForChunks( $chunkIds );
		$this->invalidate();
	}

	/**
	 * Rank a source set against a query vector.
	 *
	 * @param Embedding                 $query       Query vector.
	 * @param array<int, int>           $sourceIds   Sources.
	 * @param int                       $k           Results wanted.
	 * @param RetrievalOptions|null     $options     Parameters.
	 * @param RetrievalDiagnostics|null $diagnostics Filled when supplied.
	 * @return array<int, ScoredChunk>
	 */
	public function search(
		Embedding $query,
		array $sourceIds,
		int $k,
		?RetrievalOptions $options = null,
		?RetrievalDiagnostics $diagnostics = null
	): array {
		$options     = $options ?? new RetrievalOptions( topK: $k );
		$diagnostics = $diagnostics ?? new RetrievalDiagnostics();
		$sourceIds   = array_values( array_unique( array_filter( array_map( 'intval', $sourceIds ) ) ) );

		if ( $query->isEmpty() || array() === $sourceIds ) {
			return array();
		}

		$matrix = $this->matrix( $sourceIds, $query, $diagnostics );

		if ( $matrix->isEmpty() ) {
			$diagnostics->note( 'No vectors are stored for these sources yet.' );

			return array();
		}

		$diagnostics->scanned = $matrix->count();

		$candidates = $matrix->count() <= RetrievalOptions::EXACT_SCAN_LIMIT
			? $this->allIds( $matrix, $diagnostics )
			: $this->coarse( $matrix, $query, $options->candidates, $diagnostics );

		$diagnostics->candidates = count( $candidates );

		return $this->exact( $candidates, $query, $k, $diagnostics );
	}

	/**
	 * Drop cached matrices.
	 *
	 * @param array<int, int> $sourceIds Sources.
	 * @return void
	 */
	public function invalidate( array $sourceIds = array() ): void {
		$this->cache->forget( $sourceIds );
	}

	/**
	 * How many vectors a source holds.
	 *
	 * @param int $sourceId Source.
	 * @return int
	 */
	public function countForSource( int $sourceId ): int {
		return $this->embeddings->countForSource( $sourceId );
	}

	/**
	 * What this store is doing right now.
	 *
	 * @return array<string, mixed>
	 */
	public function describe(): array {
		return array(
			'driver'         => 'mysql_blob',
			'quantisation'   => '1 bit per dimension',
			'max_dimensions' => BinaryQuantiser::MAX_DIMENSIONS,
			'popcount'       => BinaryQuantiser::implementation(),
			'cache'          => $this->cache->describe(),
		);
	}

	/**
	 * Load the quantised matrix, from cache when possible.
	 *
	 * @param array<int, int>      $sourceIds   Sources.
	 * @param Embedding            $query       Query, for its model pin.
	 * @param RetrievalDiagnostics $diagnostics Diagnostics.
	 * @return EmbeddingMatrix
	 */
	private function matrix(
		array $sourceIds,
		Embedding $query,
		RetrievalDiagnostics $diagnostics
	): EmbeddingMatrix {
		$cached = $this->cache->get( $sourceIds, $query->provider, $query->model );

		if ( null !== $cached ) {
			$diagnostics->matrixSource = $this->cache->lastSource();

			return $cached;
		}

		$matrix = $this->embeddings->matrix( $sourceIds, $query->provider, $query->model );

		$diagnostics->matrixSource = 'database';

		if ( ! $matrix->isEmpty() ) {
			$this->cache->put( $sourceIds, $query->provider, $query->model, $matrix );
		}

		return $matrix;
	}

	/**
	 * Stage 1 — Hamming distance over the whole matrix.
	 *
	 * @param EmbeddingMatrix      $matrix      Quantised vectors.
	 * @param Embedding            $query       Query vector.
	 * @param int                  $keep        Candidates to keep.
	 * @param RetrievalDiagnostics $diagnostics Diagnostics.
	 * @return array<int, int> Chunk ids.
	 */
	private function coarse(
		EmbeddingMatrix $matrix,
		Embedding $query,
		int $keep,
		RetrievalDiagnostics $diagnostics
	): array {
		$started = microtime( true );

		$queryBits = BinaryQuantiser::quantise( $query->vector );

		if ( strlen( $queryBits ) !== $matrix->width ) {
			// The query was produced by a model of a different width to the
			// stored vectors, which means the source was indexed with one
			// model and is being searched with another. Falling back to the
			// exact pass over everything is slow but correct; ranking on
			// mismatched widths would be fast and wrong.
			$diagnostics->note(
				'The query vector and the stored vectors have different widths. '
				. 'These sources need re-indexing with the current embedding model.'
			);
			$diagnostics->strategy = 'exact_fallback';
			$diagnostics->stage1Ms = ( microtime( true ) - $started ) * 1000;

			return $matrix->ids;
		}

		$diagnostics->strategy = 'two_stage';
		$diagnostics->popcount = BinaryQuantiser::implementation();

		$width     = $matrix->width;
		$bits      = $matrix->bits;
		$ids       = $matrix->ids;
		$total     = count( $ids );
		$distances = array();

		for ( $row = 0; $row < $total; $row++ ) {
			$distances[] = BinaryQuantiser::hamming(
				substr( $bits, $row * $width, $width ),
				$queryBits
			);
		}

		// asort() over an integer array of the row positions is the whole
		// selection. A partial selection would be asymptotically better,
		// but PHP's sort is C and a hand-rolled heap is not — at ten
		// thousand rows the C sort wins comfortably.
		asort( $distances );

		$candidates = array();

		foreach ( array_keys( array_slice( $distances, 0, $keep, true ) ) as $row ) {
			$candidates[] = $ids[ $row ];
		}

		$diagnostics->stage1Ms = ( microtime( true ) - $started ) * 1000;

		return $candidates;
	}

	/**
	 * Every id in the matrix, for corpora too small to be worth a coarse pass.
	 *
	 * @param EmbeddingMatrix      $matrix      Matrix.
	 * @param RetrievalDiagnostics $diagnostics Diagnostics.
	 * @return array<int, int>
	 */
	private function allIds( EmbeddingMatrix $matrix, RetrievalDiagnostics $diagnostics ): array {
		$diagnostics->strategy = 'exact_only';

		return $matrix->ids;
	}

	/**
	 * Stage 2 — exact cosine over the survivors.
	 *
	 * @param array<int, int>      $chunkIds    Candidates.
	 * @param Embedding            $query       Query vector.
	 * @param int                  $k           Results wanted.
	 * @param RetrievalDiagnostics $diagnostics Diagnostics.
	 * @return array<int, ScoredChunk>
	 */
	private function exact(
		array $chunkIds,
		Embedding $query,
		int $k,
		RetrievalDiagnostics $diagnostics
	): array {
		if ( array() === $chunkIds ) {
			return array();
		}

		$started = microtime( true );

		$rows = $this->embeddings->exact( $chunkIds, $query->provider, $query->model );

		$vector = $query->vector;
		$norm   = $query->norm();
		$scored = array();

		foreach ( $rows as $chunkId => $row ) {
			$scored[] = new ScoredChunk(
				$chunkId,
				CosineCalculator::score( $vector, $norm, $row->f32, $row->norm ),
				$row->sourceId
			);
		}

		usort(
			$scored,
			static fn ( ScoredChunk $a, ScoredChunk $b ): int => $b->score <=> $a->score
		);

		$diagnostics->stage2Ms = ( microtime( true ) - $started ) * 1000;

		return array_slice( $scored, 0, max( 1, $k ) );
	}
}
