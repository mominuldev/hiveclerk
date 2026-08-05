<?php
/**
 * Knowledge-gap persistence contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Analytics;

use Hiveclerk\Domain\Shared\DateRange;
use Hiveclerk\Domain\Shared\Pagination;

/**
 * Stores the questions the knowledge base could not answer.
 */
interface GapRepositoryInterface {

	/**
	 * Record a gap, or count another occurrence of a known one.
	 *
	 * Returns the row as stored, so a caller can tell a first sighting
	 * from a repeat without a second query.
	 *
	 * A gap already marked resolved or ignored keeps that status: the
	 * operator's decision outranks a fresh sighting, and re-opening it
	 * would put a question they deliberately dismissed back at the top of
	 * the worklist every time somebody asked it again. The occurrence
	 * count still rises, because "we ignored this and it has been asked
	 * ninety more times" is a fact worth being able to see.
	 *
	 * @param KnowledgeGap $gap Gap to record.
	 * @return KnowledgeGap
	 */
	public function record( KnowledgeGap $gap ): KnowledgeGap;

	/**
	 * One gap by id.
	 *
	 * @param int $id Row id.
	 * @return KnowledgeGap|null
	 */
	public function find( int $id ): ?KnowledgeGap;

	/**
	 * A page of gaps.
	 *
	 * @param GapStatus|null $status  Restrict to one status.
	 * @param int|null       $agentId Restrict to one clerk.
	 * @param Pagination     $page    Page window.
	 * @return array<int, KnowledgeGap> Most asked first.
	 */
	public function paginate( ?GapStatus $status, ?int $agentId, Pagination $page ): array;

	/**
	 * How many gaps match a filter.
	 *
	 * @param GapStatus|null $status  Restrict to one status.
	 * @param int|null       $agentId Restrict to one clerk.
	 * @return int
	 */
	public function count( ?GapStatus $status, ?int $agentId = null ): int;

	/**
	 * Move a gap to a new status.
	 *
	 * @param int       $id         Row id.
	 * @param GapStatus $status     New status.
	 * @param int|null  $resolvedBy WordPress user who acted.
	 * @return bool
	 */
	public function setStatus( int $id, GapStatus $status, ?int $resolvedBy = null ): bool;

	/**
	 * Occurrences recorded per day over a range.
	 *
	 * Counted from `last_seen_at`, which is what the dashboard's "23
	 * questions went unanswered this week" banner is measuring: gaps that
	 * were live in the window, not gaps first seen in it. A gap that has
	 * been asked every day for a month is still a problem this week.
	 *
	 * @param DateRange $range Span.
	 * @return array<string, int> Keyed by Y-m-d.
	 */
	public function dailyCounts( DateRange $range ): array;

	/**
	 * Open gaps last seen within a range, most asked first.
	 *
	 * @param DateRange $range Span.
	 * @param int       $limit How many.
	 * @return array<int, KnowledgeGap>
	 */
	public function topOpen( DateRange $range, int $limit = 5 ): array;
}
