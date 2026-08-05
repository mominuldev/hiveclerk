<?php
/**
 * In-memory rollup storage.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Analytics;

use Hiveclerk\Domain\Analytics\AnalyticsRepositoryInterface;
use Hiveclerk\Domain\Analytics\DailyMetrics;
use Hiveclerk\Domain\Shared\DateRange;

/**
 * Stores rolled-up days in an array, keyed the way the table's unique
 * index is.
 *
 * The key includes the clerk as a literal string rather than as a raw
 * value, so a null clerk and clerk 0 stay distinct — which is the same
 * distinction the real repository has to make by hand, because MySQL
 * treats every NULL in a unique index as unique.
 */
final class InMemoryRollups implements AnalyticsRepositoryInterface {

	/**
	 * Stored days.
	 *
	 * @var array<string, DailyMetrics>
	 */
	public array $rows = array();

	/**
	 * Write one day.
	 *
	 * @param DailyMetrics $metrics Metrics.
	 * @return void
	 */
	public function put( DailyMetrics $metrics ): void {
		$this->rows[ self::key( $metrics->date, $metrics->agentId ) ] = $metrics;
	}

	/**
	 * Stored rows over a range.
	 *
	 * @param DateRange $range   Span.
	 * @param int|null  $agentId Clerk.
	 * @return array<int, DailyMetrics>
	 */
	public function between( DateRange $range, ?int $agentId = null ): array {
		$found = array();

		foreach ( $this->rows as $metrics ) {
			if ( $metrics->agentId === $agentId && $range->contains( $metrics->date ) ) {
				$found[] = $metrics;
			}
		}

		usort( $found, static fn ( DailyMetrics $a, DailyMetrics $b ): int => strcmp( $a->date, $b->date ) );

		return $found;
	}

	/**
	 * Stored rows per clerk.
	 *
	 * @param DateRange $range Span.
	 * @return array<int, array<int, DailyMetrics>>
	 */
	public function byAgent( DateRange $range ): array {
		$grouped = array();

		foreach ( $this->rows as $metrics ) {
			if ( null !== $metrics->agentId && $range->contains( $metrics->date ) ) {
				$grouped[ $metrics->agentId ][] = $metrics;
			}
		}

		return $grouped;
	}

	/**
	 * Most recent day written.
	 *
	 * @return string|null
	 */
	public function lastRolledUp(): ?string {
		$dates = array_map( static fn ( DailyMetrics $m ): string => $m->date, $this->rows );

		return array() === $dates ? null : max( $dates );
	}

	/**
	 * Drop old rows.
	 *
	 * @param string $before Exclusive Y-m-d.
	 * @return int
	 */
	public function purgeBefore( string $before ): int {
		$removed = 0;

		foreach ( $this->rows as $key => $metrics ) {
			if ( $metrics->date < $before ) {
				unset( $this->rows[ $key ] );
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Storage key for a day and clerk.
	 *
	 * @param string   $date    Y-m-d.
	 * @param int|null $agentId Clerk.
	 * @return string
	 */
	private static function key( string $date, ?int $agentId ): string {
		return $date . '|' . ( null === $agentId ? 'site' : (string) $agentId );
	}
}
