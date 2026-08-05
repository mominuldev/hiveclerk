<?php
/**
 * Rollup persistence contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Analytics;

use Hiveclerk\Domain\Shared\DateRange;

/**
 * Stores and reads the pre-aggregated daily figures.
 *
 * Reads return rows that exist. Filling the gaps with empty days is the
 * service's job, not this one's — a repository that invented rows would
 * make "has this day been rolled up yet?" unanswerable, and that question
 * is what the rollup job is built on.
 */
interface AnalyticsRepositoryInterface {

	/**
	 * Write one day, replacing whatever was there.
	 *
	 * The rollup re-runs over days it has already processed — a late
	 * message, a rating left this morning on yesterday's reply, a
	 * conversation that only just ended — so this has to be an upsert
	 * rather than an insert. `(date, agent_id)` carries a unique index and
	 * is the natural key.
	 *
	 * @param DailyMetrics $metrics One day, for one clerk or site-wide.
	 * @return void
	 */
	public function put( DailyMetrics $metrics ): void;

	/**
	 * Stored rows over a range, oldest first.
	 *
	 * @param DateRange $range   Span.
	 * @param int|null  $agentId Clerk, or null for the site-wide rows.
	 * @return array<int, DailyMetrics>
	 */
	public function between( DateRange $range, ?int $agentId = null ): array;

	/**
	 * Stored rows for every clerk over a range.
	 *
	 * One query for the whole roster rather than one per clerk: the
	 * per-clerk comparison renders every clerk on one screen, and a query
	 * inside that loop is how a fast page becomes a slow one as a customer
	 * hires their fourth clerk.
	 *
	 * @param DateRange $range Span.
	 * @return array<int, array<int, DailyMetrics>> Keyed by agent id.
	 */
	public function byAgent( DateRange $range ): array;

	/**
	 * The most recent day that has been rolled up.
	 *
	 * @return string|null Y-m-d, or null when nothing has.
	 */
	public function lastRolledUp(): ?string;

	/**
	 * Delete rows older than a cut-off.
	 *
	 * @param string $before Exclusive Y-m-d.
	 * @return int Rows removed.
	 */
	public function purgeBefore( string $before ): int;
}
