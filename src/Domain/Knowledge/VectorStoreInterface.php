<?php
/**
 * Vector storage contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * Where vectors live and how they are searched — the SaaS swap point.
 *
 * The self-hosted binding scans binary-quantised vectors out of MySQL
 * BLOBs; the SaaS binding will hand the same call to a dedicated vector
 * database. Everything above this line — ingestion, retrieval, chat — is
 * written against this interface and changes not at all when the binding
 * does. That is the whole argument for the interface existing on day one
 * rather than being extracted later.
 */
interface VectorStoreInterface {

	/**
	 * Store vectors, replacing any the same model already produced.
	 *
	 * @param array<int, StoredEmbedding> $embeddings Rows to write.
	 * @return int Rows written.
	 */
	public function upsertMany( array $embeddings ): int;

	/**
	 * Remove the vectors for some chunks.
	 *
	 * @param array<int, int> $chunkIds Chunks.
	 * @return void
	 */
	public function delete( array $chunkIds ): void;

	/**
	 * Rank a source set against a query vector.
	 *
	 * @param Embedding                 $query       Query vector.
	 * @param array<int, int>           $sourceIds   Sources to search.
	 * @param int                       $k           Results wanted.
	 * @param RetrievalOptions|null     $options     Search parameters.
	 * @param RetrievalDiagnostics|null $diagnostics Filled in when supplied.
	 * @return array<int, ScoredChunk> Best first.
	 */
	public function search(
		Embedding $query,
		array $sourceIds,
		int $k,
		?RetrievalOptions $options = null,
		?RetrievalDiagnostics $diagnostics = null
	): array;

	/**
	 * Drop any cached index for these sources.
	 *
	 * Called after a re-index. A stale matrix does not error — it answers
	 * from content the customer deleted, which is the failure mode this
	 * product can least afford.
	 *
	 * @param array<int, int> $sourceIds Sources, or empty for all.
	 * @return void
	 */
	public function invalidate( array $sourceIds = array() ): void;

	/**
	 * How many vectors a source holds.
	 *
	 * @param int $sourceId Source.
	 * @return int
	 */
	public function countForSource( int $sourceId ): int;

	/**
	 * What this store is and how it is currently performing.
	 *
	 * Surfaced on the system status page: the difference between a
	 * persistent object cache and the transient fallback is the difference
	 * between a fast search and a slow one, and it is invisible otherwise.
	 *
	 * @return array<string, mixed>
	 */
	public function describe(): array;
}
