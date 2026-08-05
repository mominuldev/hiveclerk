<?php
/**
 * Agent repository contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Agent;

use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Persistence for clerks.
 *
 * Declared in the domain, implemented in src/Database. Services depend on
 * this interface and never on $wpdb.
 */
interface AgentRepositoryInterface {

	/**
	 * Find by storage id.
	 *
	 * @param int $id Storage id.
	 * @return Agent|null
	 */
	public function find( int $id ): ?Agent;

	/**
	 * Find by public identifier.
	 *
	 * @param Uuid $uuid Public identifier.
	 * @return Agent|null
	 */
	public function findByUuid( Uuid $uuid ): ?Agent;

	/**
	 * Find by slug.
	 *
	 * @param string $slug Slug.
	 * @return Agent|null
	 */
	public function findBySlug( string $slug ): ?Agent;

	/**
	 * List clerks.
	 *
	 * @param Pagination       $pagination Page request.
	 * @param AgentStatus|null $status     Optional status filter.
	 * @return array<int, Agent>
	 */
	public function paginate( Pagination $pagination, ?AgentStatus $status = null ): array;

	/**
	 * Count clerks.
	 *
	 * @param AgentStatus|null $status Optional status filter.
	 * @return int
	 */
	public function count( ?AgentStatus $status = null ): int;

	/**
	 * Every clerk currently serving visitors.
	 *
	 * @return array<int, Agent>
	 */
	public function published(): array;

	/**
	 * Insert or update.
	 *
	 * @param Agent $agent Clerk.
	 * @return Agent The saved clerk, carrying its id.
	 */
	public function save( Agent $agent ): Agent;

	/**
	 * Soft delete.
	 *
	 * @param int $id Storage id.
	 * @return bool
	 */
	public function delete( int $id ): bool;

	/**
	 * Add to the month's token usage.
	 *
	 * Done in SQL rather than read-modify-write: two concurrent
	 * conversations must not lose one another's usage, or the budget cap
	 * silently stops working under exactly the load that makes it matter.
	 *
	 * @param int $id     Storage id.
	 * @param int $tokens Tokens to add.
	 * @return void
	 */
	public function incrementUsage( int $id, int $tokens ): void;
}
