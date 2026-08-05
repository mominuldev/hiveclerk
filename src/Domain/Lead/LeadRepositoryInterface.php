<?php
/**
 * Lead repository contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Persistence for leads.
 */
interface LeadRepositoryInterface {

	/**
	 * Find by storage id.
	 *
	 * @param int $id Storage id.
	 * @return Lead|null
	 */
	public function find( int $id ): ?Lead;

	/**
	 * Find by public identifier.
	 *
	 * @param Uuid $uuid Public identifier.
	 * @return Lead|null
	 */
	public function findByUuid( Uuid $uuid ): ?Lead;

	/**
	 * Find by the hash of a normalised address (FR-LED-08).
	 *
	 * @param string $hash Email hash.
	 * @return Lead|null
	 */
	public function findByEmailHash( string $hash ): ?Lead;

	/**
	 * List leads.
	 *
	 * @param Pagination           $pagination Page request.
	 * @param array<string, mixed> $filters    stage_id, status, band, owner, source, search, dates.
	 * @param string               $orderBy    Sort column.
	 * @param string               $order      ASC or DESC.
	 * @return array<int, Lead>
	 */
	public function paginate(
		Pagination $pagination,
		array $filters = array(),
		string $orderBy = 'created_at',
		string $order = 'DESC'
	): array;

	/**
	 * Count leads.
	 *
	 * @param array<string, mixed> $filters Same shape as paginate().
	 * @return int
	 */
	public function count( array $filters = array() ): int;

	/**
	 * How many leads sit in each stage, in one query.
	 *
	 * The board renders every column at once. One query per column is
	 * five queries that grow with the customer's process, and the count
	 * is the first thing on each column header.
	 *
	 * @param array<string, mixed> $filters Same shape as paginate().
	 * @return array<int, int> Counts keyed by stage id; key 0 is unstaged.
	 */
	public function countsByStage( array $filters = array() ): array;

	/**
	 * Insert or update.
	 *
	 * @param Lead $lead Lead.
	 * @return Lead
	 */
	public function save( Lead $lead ): Lead;

	/**
	 * Set the materialised score and band without touching anything else.
	 *
	 * A targeted write rather than a full save. Scoring runs after a
	 * visitor message while an operator may have the same lead open in
	 * the admin, and a whole-row update would hand one of them the
	 * other's stale copy of every field.
	 *
	 * @param int       $id    Storage id.
	 * @param int       $score New total.
	 * @param ScoreBand $band  New band.
	 * @return void
	 */
	public function updateScore( int $id, int $score, ScoreBand $band ): void;

	/**
	 * Move every lead in a stage to another one.
	 *
	 * Used when a stage is deleted. Leads are never deleted with their
	 * column: a customer tidying their board must not lose the people in
	 * it.
	 *
	 * @param int      $from Stage being emptied.
	 * @param int|null $to   Destination, or null to unstage.
	 * @return int Rows moved.
	 */
	public function reassignStage( int $from, ?int $to ): int;

	/**
	 * Delete a lead and its score events and activities.
	 *
	 * @param int $id Storage id.
	 * @return bool
	 */
	public function delete( int $id ): bool;

	/**
	 * Every lead matching a filter, for export (FR-LED-10).
	 *
	 * Yielded in batches by the caller through $offset rather than
	 * returned whole: an export of fifty thousand leads that hydrates
	 * them all at once is the request that exhausts the memory budget.
	 *
	 * @param array<string, mixed> $filters Same shape as paginate().
	 * @param int                  $limit   Batch size.
	 * @param int                  $offset  Rows to skip.
	 * @return array<int, Lead>
	 */
	public function batch( array $filters, int $limit, int $offset ): array;
}
