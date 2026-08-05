<?php
/**
 * A rollup source that records which days it was asked for.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Analytics;

use Hiveclerk\Domain\Analytics\DailyMetrics;
use Hiveclerk\Domain\Analytics\RollupSourceInterface;

/**
 * Counts nothing and remembers everything it was asked.
 *
 * Which days the service chooses to process is the entire behaviour under
 * test — a fake that only returned plausible numbers would let a rollup
 * that silently skipped a week pass.
 */
final class RecordingRollupSource implements RollupSourceInterface {

	/**
	 * Days asked for, in order.
	 *
	 * @var array<int, string>
	 */
	public array $asked = array();

	/**
	 * The qualifying score passed on the last call.
	 *
	 * @var int|null
	 */
	public ?int $qualifiedScore = null;

	/**
	 * Construct.
	 *
	 * @param string|null     $earliest Earliest day with activity.
	 * @param array<int, int> $agentIds Clerks to emit rows for.
	 */
	public function __construct(
		private readonly ?string $earliest = '2026-01-01',
		private readonly array $agentIds = array()
	) {
	}

	/**
	 * Count one day.
	 *
	 * @param string $date           Y-m-d.
	 * @param int    $qualifiedScore Qualifying score.
	 * @return array<int, DailyMetrics>
	 */
	public function metricsFor( string $date, int $qualifiedScore ): array {
		$this->asked[]        = $date;
		$this->qualifiedScore = $qualifiedScore;

		$rows = array( new DailyMetrics( $date, null, 3 ) );

		foreach ( $this->agentIds as $agentId ) {
			$rows[] = new DailyMetrics( $date, $agentId, 3 );
		}

		return $rows;
	}

	/**
	 * Earliest day with any activity.
	 *
	 * @return string|null
	 */
	public function earliestDay(): ?string {
		return $this->earliest;
	}
}
