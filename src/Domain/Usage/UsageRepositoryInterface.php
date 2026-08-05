<?php
/**
 * Usage persistence contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Usage;

/**
 * Records and aggregates metered provider calls.
 */
interface UsageRepositoryInterface {

	/**
	 * Record one call.
	 *
	 * @param UsageEvent $event Event.
	 * @return void
	 */
	public function record( UsageEvent $event ): void;

	/**
	 * Totals over a date range.
	 *
	 * @param string   $from    Inclusive UTC date, Y-m-d.
	 * @param string   $to      Inclusive UTC date, Y-m-d.
	 * @param int|null $agentId Restrict to one clerk.
	 * @return UsageSummary
	 */
	public function summarise( string $from, string $to, ?int $agentId = null ): UsageSummary;

	/**
	 * Totals per provider and model over a date range.
	 *
	 * @param string $from Inclusive UTC date, Y-m-d.
	 * @param string $to   Inclusive UTC date, Y-m-d.
	 * @return array<int, UsageSummary>
	 */
	public function byModel( string $from, string $to ): array;

	/**
	 * Daily totals over a date range, oldest first.
	 *
	 * @param string $from Inclusive UTC date, Y-m-d.
	 * @param string $to   Inclusive UTC date, Y-m-d.
	 * @return array<int, UsageSummary>
	 */
	public function daily( string $from, string $to ): array;

	/**
	 * Delete events older than a cut-off.
	 *
	 * @param string $before Exclusive UTC date, Y-m-d.
	 * @return int Rows removed.
	 */
	public function purgeBefore( string $before ): int;
}
