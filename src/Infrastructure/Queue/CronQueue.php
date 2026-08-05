<?php
/**
 * WP-Cron queue driver.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Infrastructure\Queue;

use Hiveclerk\Core\Queue\QueueInterface;

/**
 * Runs jobs through WP-Cron when Action Scheduler is absent.
 *
 * A real fallback, with real limits, stated here rather than discovered
 * later: WP-Cron does not retry a failed job, does not limit how many run
 * at once, and only fires when someone visits the site — so a quiet site
 * processes its queue slowly and a site with DISABLE_WP_CRON and no
 * system cron does not process it at all.
 *
 * That is why driver() is reported on the health screen. A customer whose
 * crawl has not progressed deserves to be told which of these they are
 * looking at, instead of being left to guess.
 *
 * "As soon as possible" is scheduled one second ahead rather than at the
 * current time: WP-Cron only considers events whose timestamp has passed,
 * and an event scheduled for exactly now can be skipped by the sweep
 * already in progress.
 */
final class CronQueue implements QueueInterface {

	/**
	 * Prefix for the custom schedules this driver registers.
	 */
	private const SCHEDULE_PREFIX = 'hiveclerk_every_';

	/**
	 * Register the interval schedules WordPress does not provide.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_filter( 'cron_schedules', array( $this, 'registerSchedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
	}

	/**
	 * Add our intervals to the WordPress schedule list.
	 *
	 * @param mixed $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function registerSchedules( mixed $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();

		foreach ( array( 60, 300, 900, 3600 ) as $seconds ) {
			$schedules[ self::SCHEDULE_PREFIX . $seconds ] = array(
				'interval' => $seconds,
				'display'  => sprintf( 'Hiveclerk: every %d seconds', $seconds ),
			);
		}

		return $schedules;
	}

	/**
	 * Run something as soon as possible.
	 *
	 * @param string               $hook Hook name.
	 * @param array<string, mixed> $args Arguments.
	 * @return bool
	 */
	public function enqueue( string $hook, array $args = array() ): bool {
		return $this->scheduleAt( time() + 1, $hook, $args );
	}

	/**
	 * Run something at a specific time.
	 *
	 * @param int                  $timestamp Unix time.
	 * @param string               $hook      Hook name.
	 * @param array<string, mixed> $args      Arguments.
	 * @return bool
	 */
	public function scheduleAt( int $timestamp, string $hook, array $args = array() ): bool {
		return true === wp_schedule_single_event( $timestamp, $hook, array( $args ) );
	}

	/**
	 * Run something repeatedly.
	 *
	 * @param int                  $interval Seconds between runs.
	 * @param string               $hook     Hook name.
	 * @param array<string, mixed> $args     Arguments.
	 * @return bool
	 */
	public function scheduleRecurring( int $interval, string $hook, array $args = array() ): bool {
		if ( $this->isPending( $hook, $args ) ) {
			return true;
		}

		return true === wp_schedule_event(
			time() + $interval,
			$this->nearestSchedule( $interval ),
			$hook,
			array( $args )
		);
	}

	/**
	 * Cancel pending work.
	 *
	 * @param string               $hook Hook name.
	 * @param array<string, mixed> $args Arguments.
	 * @return void
	 */
	public function cancel( string $hook, array $args = array() ): void {
		if ( array() === $args ) {
			wp_unschedule_hook( $hook );

			return;
		}

		wp_clear_scheduled_hook( $hook, array( $args ) );
	}

	/**
	 * Whether matching work is already pending.
	 *
	 * @param string               $hook Hook name.
	 * @param array<string, mixed> $args Arguments.
	 * @return bool
	 */
	public function isPending( string $hook, array $args = array() ): bool {
		if ( array() !== $args ) {
			return false !== wp_next_scheduled( $hook, array( $args ) );
		}

		foreach ( self::events() as $eventHook => $_ ) {
			if ( $eventHook === $hook ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * How many of our jobs are waiting.
	 *
	 * @return int
	 */
	public function depth(): int {
		$count = 0;

		foreach ( self::events() as $hook => $instances ) {
			if ( str_starts_with( $hook, 'hiveclerk/' ) ) {
				$count += $instances;
			}
		}

		return $count;
	}

	/**
	 * Driver name.
	 *
	 * @return string
	 */
	public function driver(): string {
		return 'wp-cron';
	}

	/**
	 * The schedule slug closest to a requested interval.
	 *
	 * WP-Cron only accepts named schedules, so an arbitrary interval is
	 * rounded to the nearest one we registered rather than rejected.
	 *
	 * @param int $interval Seconds.
	 * @return string
	 */
	private function nearestSchedule( int $interval ): string {
		$available = array( 60, 300, 900, 3600 );
		$closest   = $available[0];

		foreach ( $available as $candidate ) {
			if ( abs( $candidate - $interval ) < abs( $closest - $interval ) ) {
				$closest = $candidate;
			}
		}

		return self::SCHEDULE_PREFIX . $closest;
	}

	/**
	 * Pending event counts by hook.
	 *
	 * @return array<string, int>
	 */
	private static function events(): array {
		$cron   = _get_cron_array();
		$counts = array();

		if ( ! is_array( $cron ) ) {
			return $counts;
		}

		foreach ( $cron as $timestamp => $hooks ) {
			unset( $timestamp );

			if ( ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $hook => $instances ) {
				if ( is_string( $hook ) && is_array( $instances ) ) {
					$counts[ $hook ] = ( $counts[ $hook ] ?? 0 ) + count( $instances );
				}
			}
		}

		return $counts;
	}
}
