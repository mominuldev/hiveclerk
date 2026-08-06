<?php
/**
 * Daily rollup.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Analytics\Services;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Analytics\AnalyticsRepositoryInterface;
use Hiveclerk\Domain\Analytics\DailyMetrics;
use Hiveclerk\Domain\Analytics\RollupSourceInterface;
use Hiveclerk\Domain\Lead\ScoreBand;

/**
 * Turns yesterday and the days before it into stored counters.
 *
 * ## What it processes, and why it is not just "yesterday"
 *
 * A day's figures are not final at midnight. A rating left this morning
 * belongs to yesterday's conversation; a handoff accepted overnight
 * belongs to the day the conversation started; a conversation that opened
 * at 23:55 collects most of its messages on the following date. Sealing a
 * day and never revisiting it would leave every one of those permanently
 * uncounted, and nothing would report it — the dashboard would simply be
 * quietly low.
 *
 * So a caught-up site re-processes a trailing window on every run. Only
 * once it is behind — a fresh install with history, or a site whose cron
 * has not fired for a week — does it walk forward instead, taking a
 * bounded batch of days per run and re-enqueueing itself.
 *
 * Today is deliberately never stored. It is computed live and merged by
 * {@see AnalyticsService}, because a stored partial day is a number that
 * is wrong for twenty-three hours and right for one.
 */
final class RollupService {

	/**
	 * Days re-processed on a caught-up site.
	 *
	 * Seven, so a rating or a handoff arriving up to a week late is still
	 * counted. Beyond that the day stops changing in practice, and the
	 * cost of the window is paid hourly.
	 */
	public const REPROCESS_DAYS = 7;

	/**
	 * Days processed in one run.
	 *
	 * The batch that keeps a backfill under the twenty-second budget every
	 * job in this product holds itself to. A year of history takes about
	 * fifteen runs, and the job re-enqueues itself until it is done rather
	 * than waiting an hour between them.
	 */
	public const BATCH_DAYS = 25;

	/**
	 * How long today's live figures are reused before being recounted.
	 *
	 * Today is counted from the live tables on every request that asks for
	 * it, and counting it is not cheap: the qualified-lead figure groups
	 * the whole of `hvc_lead_scores` before the day is filtered, so its
	 * cost grows with all history rather than with today. A dashboard that
	 * asks for the site-wide series and the per-clerk roster pays it
	 * twice, and pays it again on every refresh — inside the 400 ms budget
	 * an admin request is held to.
	 *
	 * A minute is short enough that nobody watching a live conversation
	 * notices, and long enough that a page of panels and an operator
	 * refreshing it cost one count rather than a dozen.
	 */
	private const TODAY_TTL = 60;

	/**
	 * Transient holding today's live figures.
	 */
	private const TODAY_TRANSIENT = 'hvc_rollup_today';

	/**
	 * Today's rows, once per request.
	 *
	 * @var array<string, array<int, DailyMetrics>>
	 */
	private array $todayMemo = array();

	/**
	 * Construct.
	 *
	 * @param RollupSourceInterface        $source   Counts the live tables.
	 * @param AnalyticsRepositoryInterface $rollups  Stores the counts.
	 * @param SettingsRepository           $settings Settings.
	 * @param ClockInterface               $clock    Clock.
	 */
	public function __construct(
		private readonly RollupSourceInterface $source,
		private readonly AnalyticsRepositoryInterface $rollups,
		private readonly SettingsRepository $settings,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Process the next batch of days.
	 *
	 * @return array{processed: int, remaining: int, from: string|null, to: string|null}
	 */
	public function run(): array {
		$pending = $this->pendingDays();

		if ( array() === $pending ) {
			return array(
				'processed' => 0,
				'remaining' => 0,
				'from'      => null,
				'to'        => null,
			);
		}

		$batch = array_slice( $pending, 0, self::BATCH_DAYS );

		foreach ( $batch as $day ) {
			$this->rollFor( $day );
		}

		return array(
			'processed' => count( $batch ),
			'remaining' => count( $pending ) - count( $batch ),
			'from'      => $batch[0],
			'to'        => $batch[ count( $batch ) - 1 ],
		);
	}

	/**
	 * Count one day and store every row it produced.
	 *
	 * @param string $date Y-m-d, UTC.
	 * @return int Rows written.
	 */
	public function rollFor( string $date ): int {
		$rows = $this->source->metricsFor( $date, $this->qualifiedScore() );

		foreach ( $rows as $metrics ) {
			$this->rollups->put( $metrics );
		}

		/**
		 * Fires after one day has been rolled up.
		 *
		 * @param string                   $date UTC day.
		 * @param array<int, DailyMetrics> $rows What was stored.
		 */
		do_action( 'hiveclerk/analytics/rolled_up', $date, $rows );

		return count( $rows );
	}

	/**
	 * Today's figures, counted live and not stored.
	 *
	 * @param int|null $agentId Clerk, or null for site-wide.
	 * @return DailyMetrics
	 */
	public function today( ?int $agentId = null ): DailyMetrics {
		$date = $this->nowUtc()->format( 'Y-m-d' );

		foreach ( $this->liveToday( $date ) as $metrics ) {
			if ( $metrics->agentId === $agentId ) {
				return $metrics;
			}
		}

		return DailyMetrics::empty( $date, $agentId );
	}

	/**
	 * Today's rows, counted at most once a minute.
	 *
	 * Memoised for the request as well as cached across requests, because
	 * a single dashboard load asks for the site-wide figure and the
	 * per-clerk roster separately and would otherwise count twice before
	 * the cache had anything in it.
	 *
	 * Not written through {@see AnalyticsRepositoryInterface}: today is
	 * deliberately never stored as a rollup row, and a cache entry that
	 * expires on its own is not the same thing as a stored partial day
	 * that would outlive the day it describes.
	 *
	 * @param string $date UTC day.
	 * @return array<int, DailyMetrics>
	 */
	private function liveToday( string $date ): array {
		if ( isset( $this->todayMemo[ $date ] ) ) {
			return $this->todayMemo[ $date ];
		}

		$cached = get_transient( self::TODAY_TRANSIENT . '_' . $date );

		if ( is_array( $cached ) ) {
			$rows = array();

			foreach ( $cached as $row ) {
				if ( $row instanceof DailyMetrics ) {
					$rows[] = $row;
				}
			}

			// Only trusted whole. A partially decoded cache would report
			// some clerks and silently drop others, which reads as a quiet
			// day rather than as a broken cache.
			if ( count( $rows ) === count( $cached ) ) {
				$this->todayMemo[ $date ] = $rows;

				return $rows;
			}
		}

		$rows = $this->source->metricsFor( $date, $this->qualifiedScore() );

		set_transient( self::TODAY_TRANSIENT . '_' . $date, $rows, self::TODAY_TTL );

		$this->todayMemo[ $date ] = $rows;

		return $rows;
	}

	/**
	 * Today's figures for every clerk that saw activity.
	 *
	 * The per-clerk comparison needs the same live merge the site-wide
	 * series gets. Without it the roster panel reads zero for every clerk
	 * on a site that went live this morning — which is precisely the
	 * morning somebody is watching it.
	 *
	 * @return array<int, DailyMetrics> Keyed by clerk id.
	 */
	public function todayByAgent(): array {
		$date = $this->nowUtc()->format( 'Y-m-d' );
		$rows = array();

		foreach ( $this->liveToday( $date ) as $metrics ) {
			if ( null !== $metrics->agentId ) {
				$rows[ $metrics->agentId ] = $metrics;
			}
		}

		return $rows;
	}

	/**
	 * The days this run should process, oldest first.
	 *
	 * @return array<int, string>
	 */
	private function pendingDays(): array {
		$earliest = $this->source->earliestDay();

		if ( null === $earliest ) {
			// Nothing has ever happened on this site. There is no day to
			// count, and writing zeroes would fill the table with rows
			// that say a clerk was ignored on days it did not exist.
			return array();
		}

		$yesterday = $this->nowUtc()->modify( '-1 day' )->format( 'Y-m-d' );

		if ( $yesterday < $earliest ) {
			return array();
		}

		$last = $this->rollups->lastRolledUp();

		if ( null === $last ) {
			$start = $earliest;
		} elseif ( $last >= $yesterday ) {
			// Caught up: refresh the trailing window.
			$start = max(
				$earliest,
				$this->day( $yesterday )
					->modify( sprintf( '-%d days', self::REPROCESS_DAYS - 1 ) )
					->format( 'Y-m-d' )
			);
		} else {
			// Behind: go forward from where the last run stopped. Days
			// already written are stable enough that re-doing them would
			// only slow the catch-up down.
			$start = $this->day( $last )->modify( '+1 day' )->format( 'Y-m-d' );
		}

		$days   = array();
		$cursor = $this->day( $start );

		while ( $cursor->format( 'Y-m-d' ) <= $yesterday ) {
			$days[] = $cursor->format( 'Y-m-d' );
			$cursor = $cursor->modify( '+1 day' );
		}

		return $days;
	}

	/**
	 * The score at which a lead counts as qualified.
	 *
	 * @return int
	 */
	private function qualifiedScore(): int {
		$bands = $this->settings->get( 'leads.bands' );
		$value = is_array( $bands ) ? ( $bands['qualified'] ?? null ) : null;

		return is_numeric( $value ) ? max( 0, (int) $value ) : ScoreBand::DEFAULTS['qualified'];
	}

	/**
	 * Now, in UTC.
	 *
	 * @return DateTimeImmutable
	 */
	private function nowUtc(): DateTimeImmutable {
		return $this->clock->now()->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Parse a Y-m-d into a UTC day.
	 *
	 * @param string $date Y-m-d.
	 * @return DateTimeImmutable
	 */
	private function day( string $date ): DateTimeImmutable {
		return new DateTimeImmutable( $date . ' 00:00:00', new DateTimeZone( 'UTC' ) );
	}
}
