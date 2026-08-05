<?php
/**
 * Email log storage contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

use Hiveclerk\Domain\Shared\Pagination;

/**
 * Where sent email is recorded.
 */
interface EmailLogRepositoryInterface {

	/**
	 * Append a row.
	 *
	 * @param EmailLogEntry $entry Row.
	 * @return EmailLogEntry
	 */
	public function append( EmailLogEntry $entry ): EmailLogEntry;

	/**
	 * A page of the log, newest first.
	 *
	 * @param Pagination      $pagination Page request.
	 * @param int|null        $leadId     Filter by recipient.
	 * @param SendStatus|null $status     Filter by outcome.
	 * @return array<int, EmailLogEntry>
	 */
	public function paginate( Pagination $pagination, ?int $leadId = null, ?SendStatus $status = null ): array;

	/**
	 * How many rows match a filter.
	 *
	 * @param int|null        $leadId Filter by recipient.
	 * @param SendStatus|null $status Filter by outcome.
	 * @return int
	 */
	public function countMatching( ?int $leadId = null, ?SendStatus $status = null ): int;

	/**
	 * How many emails went out since a moment.
	 *
	 * The per-site hourly ceiling reads this. Counted from the log rather
	 * than a counter, because a counter and a log that disagree is a bug
	 * nobody finds until a customer's host suspends them for volume.
	 *
	 * @param string $since UTC MySQL DATETIME.
	 * @return int
	 */
	public function sentSince( string $since ): int;

	/**
	 * Whether a step has already been sent to an enrolment.
	 *
	 * The guard against a double send when a job runs twice — which
	 * WP-Cron does on any site with overlapping requests.
	 *
	 * @param int $enrollmentId Enrolment.
	 * @param int $stepId       Step.
	 * @return bool
	 */
	public function alreadySent( int $enrollmentId, int $stepId ): bool;

	/**
	 * Per-sequence counts by outcome.
	 *
	 * @param int $sequenceId Sequence.
	 * @return array<string, int>
	 */
	public function statsFor( int $sequenceId ): array;

	/**
	 * Every logged send to one address, newest first.
	 *
	 * Looked up by address rather than by lead id because the log outlives
	 * the lead: a merge, a deletion or an earlier erasure can leave rows
	 * whose `lead_id` points at nothing, and a privacy request that only
	 * followed the id would report and erase less than the site holds.
	 *
	 * @param string $email  Normalised address.
	 * @param int    $limit  Maximum rows.
	 * @param int    $offset Rows to skip.
	 * @return array<int, EmailLogEntry>
	 */
	public function forEmail( string $email, int $limit, int $offset = 0 ): array;

	/**
	 * Delete every logged send to one address.
	 *
	 * @param string $email Normalised address.
	 * @return int Rows deleted.
	 */
	public function deleteForEmail( string $email ): int;
}
