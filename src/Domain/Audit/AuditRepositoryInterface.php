<?php
/**
 * Audit persistence contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Audit;

use Hiveclerk\Domain\Shared\Pagination;

/**
 * Append-only storage for audit records.
 *
 * There is deliberately no update and no delete-by-id. An audit log that
 * can be edited is not an audit log, and the only removal offered is a
 * whole-range purge that itself gets recorded.
 */
interface AuditRepositoryInterface {

	/**
	 * Append a record.
	 *
	 * @param AuditEntry $entry Entry.
	 * @return void
	 */
	public function append( AuditEntry $entry ): void;

	/**
	 * A page of records, newest first.
	 *
	 * @param Pagination  $pagination Page request.
	 * @param string|null $action     Filter by exact action.
	 * @param int|null    $userId     Filter by user.
	 * @return array<int, AuditEntry>
	 */
	public function paginate(
		Pagination $pagination,
		?string $action = null,
		?int $userId = null
	): array;

	/**
	 * Total records matching a filter.
	 *
	 * @param string|null $action Filter by exact action.
	 * @param int|null    $userId Filter by user.
	 * @return int
	 */
	public function total( ?string $action = null, ?int $userId = null ): int;

	/**
	 * Distinct action names present in the log.
	 *
	 * @return array<int, string>
	 */
	public function actions(): array;

	/**
	 * Delete records older than a cut-off.
	 *
	 * @param string $before Exclusive UTC date, Y-m-d.
	 * @return int Rows removed.
	 */
	public function purgeBefore( string $before ): int;
}
