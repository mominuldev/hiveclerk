<?php
/**
 * Document repository contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

use Hiveclerk\Domain\Shared\Pagination;

/**
 * Persistence for documents.
 */
interface DocumentRepositoryInterface {

	/**
	 * Find by storage id.
	 *
	 * @param int $id Storage id.
	 * @return Document|null
	 */
	public function find( int $id ): ?Document;

	/**
	 * Find one document within a source by its external identifier.
	 *
	 * The lookup ingestion depends on. A post id, a product id, a URL —
	 * whatever the source calls the thing — so a re-index updates the
	 * document it already has rather than inserting a second copy.
	 *
	 * @param int    $sourceId   Source.
	 * @param string $externalId Identifier within the source.
	 * @return Document|null
	 */
	public function findByExternalId( int $sourceId, string $externalId ): ?Document;

	/**
	 * List documents in a source.
	 *
	 * @param int        $sourceId   Source.
	 * @param Pagination $pagination Page request.
	 * @return array<int, Document>
	 */
	public function forSource( int $sourceId, Pagination $pagination ): array;

	/**
	 * Count documents in a source.
	 *
	 * @param int $sourceId Source.
	 * @return int
	 */
	public function countForSource( int $sourceId ): int;

	/**
	 * Titles and URLs for several documents.
	 *
	 * Deliberately not find() in a loop. Documents carry a LONGTEXT column
	 * holding the whole normalised page, and a citation header needs
	 * neither it nor a query per result — five results on a site of long
	 * pages is several megabytes read to display five headings.
	 *
	 * @param array<int, int> $ids Document ids.
	 * @return array<int, array{title: string, url: string|null}> Keyed by id.
	 */
	public function titles( array $ids ): array;

	/**
	 * Every external id currently stored for a source.
	 *
	 * Read at the end of a sync to find documents the source no longer
	 * contains. A page deleted from a site has to leave the index too, or
	 * the clerk keeps answering from it — which is worse than never
	 * having indexed it, because the customer believes it was removed.
	 *
	 * @param int $sourceId Source.
	 * @return array<string, int> External id to storage id.
	 */
	public function externalIds( int $sourceId ): array;

	/**
	 * Insert or update.
	 *
	 * @param Document $document Document.
	 * @return Document
	 */
	public function save( Document $document ): Document;

	/**
	 * Delete a document and everything derived from it.
	 *
	 * @param int $id Storage id.
	 * @return bool
	 */
	public function delete( int $id ): bool;

	/**
	 * Delete every document in a source.
	 *
	 * @param int $sourceId Source.
	 * @return int Rows removed.
	 */
	public function deleteForSource( int $sourceId ): int;
}
