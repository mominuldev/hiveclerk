<?php
/**
 * Sync log storage contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

use Hiveclerk\Domain\Shared\Pagination;

/**
 * Where sync attempts are recorded.
 */
interface SyncLogRepositoryInterface {

	/**
	 * Append an attempt.
	 *
	 * @param SyncLogEntry $entry Attempt.
	 * @return SyncLogEntry
	 */
	public function append( SyncLogEntry $entry ): SyncLogEntry;

	/**
	 * A page of the log, newest first.
	 *
	 * @param Pagination  $pagination    Page request.
	 * @param int|null    $integrationId Filter by connection.
	 * @param SyncStatus|null $status    Filter by outcome.
	 * @return array<int, SyncLogEntry>
	 */
	public function paginate( Pagination $pagination, ?int $integrationId = null, ?SyncStatus $status = null ): array;

	/**
	 * How many rows match a filter.
	 *
	 * @param int|null        $integrationId Filter by connection.
	 * @param SyncStatus|null $status        Filter by outcome.
	 * @return int
	 */
	public function countMatching( ?int $integrationId = null, ?SyncStatus $status = null ): int;

	/**
	 * The most recent successful push for a lead, if there was one.
	 *
	 * Carries the external id, which is what turns the second push of the
	 * same person into an update rather than a duplicate contact.
	 *
	 * @param int $integrationId Connection.
	 * @param int $leadId        Lead.
	 * @return SyncLogEntry|null
	 */
	public function lastSuccess( int $integrationId, int $leadId ): ?SyncLogEntry;

	/**
	 * How many distinct leads have reached a connection.
	 *
	 * The "214 contacts" line on the card in D11 §8. Counted from our own
	 * log rather than asked of the CRM: the number that matters to the
	 * operator is how many *we* sent, and a CRM's own total includes
	 * contacts from every other source they use.
	 *
	 * @param int $integrationId Connection.
	 * @return int
	 */
	public function contactsSynced( int $integrationId ): int;

	/**
	 * Consecutive failures since the last success.
	 *
	 * @param int $integrationId Connection.
	 * @return int
	 */
	public function recentFailures( int $integrationId ): int;

	/**
	 * Remove every row for a connection.
	 *
	 * @param int $integrationId Connection.
	 * @return int Rows removed.
	 */
	public function purge( int $integrationId ): int;
}
