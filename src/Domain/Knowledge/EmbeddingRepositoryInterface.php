<?php
/**
 * Embedding persistence contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * Persistence for vectors.
 *
 * Narrower than the usual repository shape, and shaped by the access
 * pattern rather than by the row: nothing ever wants "the embedding for
 * chunk 9812". Stage 1 wants every quantised vector in a source set as
 * cheaply as possible, stage 2 wants the exact vectors for a couple of
 * hundred ids, and the embedding job wants to know which chunks are still
 * missing one. Those three are the whole interface.
 */
interface EmbeddingRepositoryInterface {

	/**
	 * Write vectors, replacing any this model already produced.
	 *
	 * @param array<int, StoredEmbedding> $embeddings Rows.
	 * @return int Rows written.
	 */
	public function saveMany( array $embeddings ): int;

	/**
	 * Load the quantised matrix for a source set.
	 *
	 * @param array<int, int> $sourceIds Sources.
	 * @param string          $provider  Pinned provider.
	 * @param string          $model     Pinned model.
	 * @return EmbeddingMatrix
	 */
	public function matrix( array $sourceIds, string $provider, string $model ): EmbeddingMatrix;

	/**
	 * Load the exact vectors for specific chunks.
	 *
	 * @param array<int, int> $chunkIds Chunks.
	 * @param string          $provider Pinned provider.
	 * @param string          $model    Pinned model.
	 * @return array<int, StoredEmbedding> Keyed by chunk id.
	 */
	public function exact( array $chunkIds, string $provider, string $model ): array;

	/**
	 * Chunks in a source that have no vector from this model yet.
	 *
	 * @param int    $sourceId Source.
	 * @param string $provider Provider.
	 * @param string $model    Model.
	 * @param int    $limit    Batch ceiling.
	 * @return array<int, int> Chunk ids.
	 */
	public function pendingChunkIds( int $sourceId, string $provider, string $model, int $limit ): array;

	/**
	 * How many chunks in a source still need a vector.
	 *
	 * @param int    $sourceId Source.
	 * @param string $provider Provider.
	 * @param string $model    Model.
	 * @return int
	 */
	public function countPending( int $sourceId, string $provider, string $model ): int;

	/**
	 * How many vectors a source holds.
	 *
	 * @param int $sourceId Source.
	 * @return int
	 */
	public function countForSource( int $sourceId ): int;

	/**
	 * Which provider and model a source's vectors were produced by.
	 *
	 * @param int $sourceId Source.
	 * @return array<int, array{provider: string, model: string, dimensions: int, count: int}>
	 */
	public function modelsForSource( int $sourceId ): array;

	/**
	 * Remove vectors for specific chunks.
	 *
	 * @param array<int, int> $chunkIds Chunks.
	 * @return int Rows removed.
	 */
	public function deleteForChunks( array $chunkIds ): int;

	/**
	 * Remove every vector in a source.
	 *
	 * @param int         $sourceId Source.
	 * @param string|null $provider Limit to one provider, or null for all.
	 * @param string|null $model    Limit to one model, or null for all.
	 * @return int Rows removed.
	 */
	public function deleteForSource( int $sourceId, ?string $provider = null, ?string $model = null ): int;
}
