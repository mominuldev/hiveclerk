<?php
/**
 * Where a day's figures are counted from.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Analytics;

/**
 * Counts one day out of the live tables.
 *
 * Separate from {@see AnalyticsRepositoryInterface} because they are two
 * different things that happen to share a schema: this one reads
 * conversations, messages, visitors, leads and usage events; that one
 * reads and writes the single table those counts are stored in. Merging
 * them would give the dashboard's repository a method that scans the
 * message table, which is the one thing the rollup exists to prevent
 * anybody doing by accident.
 */
interface RollupSourceInterface {

	/**
	 * Count one UTC day.
	 *
	 * Returns the site-wide row first, followed by one row per clerk that
	 * saw activity. A clerk with nothing that day is absent rather than
	 * present with zeroes — an empty row is indistinguishable from a
	 * clerk that was paused, and the series builder fills gaps anyway.
	 *
	 * The qualifying score arrives as an argument rather than being read
	 * here. Which score counts as qualified is the customer's scoring
	 * policy, and a persistence class that reached into a module's
	 * settings to find out would invert the layering for one integer.
	 *
	 * @param string $date           Y-m-d, UTC.
	 * @param int    $qualifiedScore Score at which a lead counts as qualified.
	 * @return array<int, DailyMetrics>
	 */
	public function metricsFor( string $date, int $qualifiedScore ): array;

	/**
	 * The earliest day that has any activity at all.
	 *
	 * The rollup uses it to know where to stop walking backwards on a
	 * fresh install rather than grinding through empty days to the epoch.
	 *
	 * @return string|null Y-m-d, or null on a site with no conversations.
	 */
	public function earliestDay(): ?string;
}
