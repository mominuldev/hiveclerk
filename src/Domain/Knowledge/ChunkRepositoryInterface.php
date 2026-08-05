<?php
/**
 * Chunk repository contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * Persistence for chunks.
 */
interface ChunkRepositoryInterface {

	/**
	 * Find by storage id.
	 *
	 * @param int $id Storage id.
	 * @return Chunk|null
	 */
	public function find( int $id ): ?Chunk;

	/**
	 * Load several chunks at once.
	 *
	 * Retrieval finds ids first and needs their text second. Loading them
	 * one at a time would issue a query per result on the hot path.
	 *
	 * @param array<int, int> $ids Storage ids.
	 * @return array<int, Chunk>
	 */
	public function findMany( array $ids ): array;

	/**
	 * Chunks of one document, in order.
	 *
	 * @param int $documentId Document.
	 * @return array<int, Chunk>
	 */
	public function forDocument( int $documentId ): array;

	/**
	 * Replace a document's chunks with a new set.
	 *
	 * One operation rather than delete-then-insert by the caller, because
	 * a failure between the two would leave the document indexed as
	 * having no content at all — which reads to the clerk as a page that
	 * exists and says nothing.
	 *
	 * @param int               $documentId Document.
	 * @param array<int, Chunk> $chunks     Replacement chunks.
	 * @return array<int, Chunk> Saved chunks, with ids.
	 */
	public function replaceForDocument( int $documentId, array $chunks ): array;

	/**
	 * Count chunks in a source.
	 *
	 * @param int $sourceId Source.
	 * @return int
	 */
	public function countForSource( int $sourceId ): int;

	/**
	 * Total tokens held by a source.
	 *
	 * @param int $sourceId Source.
	 * @return int
	 */
	public function tokensForSource( int $sourceId ): int;

	/**
	 * Delete every chunk in a source.
	 *
	 * @param int $sourceId Source.
	 * @return int Rows removed.
	 */
	public function deleteForSource( int $sourceId ): int;
}
