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
	 * @param Pagination           $pagination Page request.
	 * @param array<string, mixed> $filters    status, role_preset, search.
	 * @return array<int, Agent>
	 */
	public function paginate( Pagination $pagination, array $filters = array() ): array;

	/**
	 * Count clerks.
	 *
	 * @param array<string, mixed> $filters Same shape as paginate().
	 * @return int
	 */
	public function count( array $filters = array() ): int;

	/**
	 * Every clerk currently serving visitors.
	 *
	 * @return array<int, Agent>
	 */
	public function published(): array;

	/**
	 * Whether a slug is already in use.
	 *
	 * Asked before saving rather than caught afterwards: the unique key on
	 * the column turns a collision into a failed insert with no id, which
	 * the caller would otherwise report as a mysterious save failure.
	 *
	 * @param string   $slug      Candidate slug.
	 * @param int|null $exceptId  Clerk allowed to hold it — itself, when renaming.
	 * @return bool
	 */
	public function slugTaken( string $slug, ?int $exceptId = null ): bool;

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
	 * Knowledge sources this clerk may read, highest priority first.
	 *
	 * A clerk with no sources is not an error and must not be treated as
	 * one: it answers from its instructions alone, which is a legitimate
	 * configuration for a clerk whose whole job is qualification.
	 *
	 * @param int $agentId Storage id.
	 * @return array<int, int>
	 */
	public function sourceIds( int $agentId ): array;

	/**
	 * How many sources each of these clerks reads, in one query.
	 *
	 * The roster renders on every screen in the admin, so a source count
	 * per clerk is a query per clerk on every page load. One grouped
	 * statement instead.
	 *
	 * @param array<int, int> $agentIds Storage ids.
	 * @return array<int, int> Counts keyed by agent id; absent means none.
	 */
	public function sourceCounts( array $agentIds ): array;

	/**
	 * Give a clerk access to a knowledge source.
	 *
	 * @param int $agentId  Storage id.
	 * @param int $sourceId Knowledge source id.
	 * @param int $priority Higher sorts first.
	 * @return void
	 */
	public function attachSource( int $agentId, int $sourceId, int $priority = 0 ): void;

	/**
	 * Replace a clerk's source list with exactly these sources.
	 *
	 * One statement per removal rather than delete-all-then-insert: the
	 * latter leaves a clerk with no knowledge for the width of the
	 * transaction, and a visitor asking a question in that window gets a
	 * confident "I don't have that in what I've been given to read".
	 *
	 * @param int              $agentId   Storage id.
	 * @param array<int, int>  $sourceIds Sources, in priority order.
	 * @return void
	 */
	public function syncSources( int $agentId, array $sourceIds ): void;

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

	/**
	 * Zero the month's usage and record when the period started.
	 *
	 * @param int    $id      Storage id.
	 * @param string $resetAt UTC timestamp the new period began, Y-m-d H:i:s.
	 * @return void
	 */
	public function resetUsage( int $id, string $resetAt ): void;
}
