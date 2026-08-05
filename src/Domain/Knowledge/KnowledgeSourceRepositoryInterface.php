<?php
/**
 * Knowledge source repository contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Persistence for knowledge sources.
 */
interface KnowledgeSourceRepositoryInterface {

	/**
	 * Find by storage id.
	 *
	 * @param int $id Storage id.
	 * @return KnowledgeSource|null
	 */
	public function find( int $id ): ?KnowledgeSource;

	/**
	 * Find by public identifier.
	 *
	 * @param Uuid $uuid Public identifier.
	 * @return KnowledgeSource|null
	 */
	public function findByUuid( Uuid $uuid ): ?KnowledgeSource;

	/**
	 * List sources.
	 *
	 * @param Pagination $pagination Page request.
	 * @return array<int, KnowledgeSource>
	 */
	public function paginate( Pagination $pagination ): array;

	/**
	 * Count sources.
	 *
	 * @return int
	 */
	public function count(): int;

	/**
	 * Sources assigned to a clerk.
	 *
	 * @param int $agentId Clerk.
	 * @return array<int, KnowledgeSource>
	 */
	public function forAgent( int $agentId ): array;

	/**
	 * Total chunks across every source, for tier cap enforcement.
	 *
	 * @return int
	 */
	public function totalChunks(): int;

	/**
	 * Insert or update.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return KnowledgeSource
	 */
	public function save( KnowledgeSource $source ): KnowledgeSource;

	/**
	 * Soft delete.
	 *
	 * @param int $id Storage id.
	 * @return bool
	 */
	public function delete( int $id ): bool;
}
