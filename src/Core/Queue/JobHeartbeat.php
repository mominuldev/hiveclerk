<?php
/**
 * When each background job last actually ran.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Queue;

/**
 * Records that a job executed, so "scheduled" can be told from "running".
 *
 * ## The failure this exists to make visible
 *
 * A scheduled event reschedules itself whether or not anything answered it.
 * WP-Cron fires the action, no callback is registered, the event is booked
 * again for the next interval, and nothing anywhere reports a problem. The
 * schedule stays pristine while the work stops completely.
 *
 * That is not hypothetical. On Hostinger the web SAPI and the CLI run
 * different PHP versions, and only the web one was raised to the 8.3 this
 * plugin requires — so on any install that follows the standard advice of
 * `DISABLE_WP_CRON` plus a system cron calling `wp-cron.php`, the cron runs
 * under a PHP the plugin refuses to boot on. Measured on a real account:
 * `has_action()` returns false for all three recurring hooks while
 * `wp cron event list` shows every one of them with a healthy next run.
 *
 * The system status screen read the schedule, so it called all three fine.
 * It was reporting the one thing that stays healthy in this failure.
 *
 * ## Why a separate record rather than a longer look at the schedule
 *
 * There is nothing in the schedule to look at. `next_run` advancing is not
 * evidence of anything having happened; it is evidence of WordPress being
 * able to do arithmetic. The only way to know a job ran is for the job to
 * say so, which means writing it down at the moment it happens.
 *
 * ## Cost
 *
 * One option, `autoload = false`, holding a small map of hook to timestamp.
 * Written once per job execution — the jobs here run at most every five
 * minutes, so this is a handful of writes an hour against work that is
 * already touching the database heavily. Deliberately not a table: it is
 * bounded by the number of jobs the product has, and a table would be a
 * migration for eleven rows.
 */
final class JobHeartbeat {

	/**
	 * Where the record lives.
	 */
	public const OPTION = 'hiveclerk_job_runs';

	/**
	 * Note that a job just executed.
	 *
	 * Recorded for a job that threw as well as one that succeeded, and the
	 * two are kept apart. They answer different questions: `ran_at` says
	 * whether anything is reaching the job at all — the cron question — and
	 * `failed_at` says whether it is working once reached. A job that is
	 * failing every run is a different problem from a job nothing is
	 * calling, and collapsing them would hide the second behind the first.
	 *
	 * @param string $hook   Hook the job answers.
	 * @param int    $now    Current UNIX time.
	 * @param bool   $failed Whether the run threw.
	 * @return void
	 */
	public static function record( string $hook, int $now, bool $failed = false ): void {
		$runs = self::all();

		$entry = $runs[ $hook ] ?? array(
			'ran_at'    => 0,
			'failed_at' => 0,
		);

		$entry['ran_at'] = $now;

		if ( $failed ) {
			$entry['failed_at'] = $now;
		}

		$runs[ $hook ] = $entry;

		update_option( self::OPTION, $runs, false );
	}

	/**
	 * Every recorded run.
	 *
	 * @return array<string, array{ran_at: int, failed_at: int}>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$runs = array();

		foreach ( $stored as $hook => $entry ) {
			if ( ! is_string( $hook ) || ! is_array( $entry ) ) {
				continue;
			}

			$runs[ $hook ] = array(
				'ran_at'    => is_numeric( $entry['ran_at'] ?? null ) ? (int) $entry['ran_at'] : 0,
				'failed_at' => is_numeric( $entry['failed_at'] ?? null ) ? (int) $entry['failed_at'] : 0,
			);
		}

		return $runs;
	}

	/**
	 * When a hook last ran, or null if it never has here.
	 *
	 * @param string $hook Hook name.
	 * @return int|null
	 */
	public static function lastRun( string $hook ): ?int {
		$runs = self::all();
		$at   = $runs[ $hook ]['ran_at'] ?? 0;

		return $at > 0 ? (int) $at : null;
	}

	/**
	 * Whether a hook has gone quiet given how often it should run.
	 *
	 * Two intervals plus an hour. One interval is far too tight — WP-Cron
	 * fires on traffic, so a five-minute job on a site nobody visited for
	 * ten minutes is late without anything being wrong. Two intervals and
	 * an hour of slack means a genuinely quiet site stays green and a job
	 * that has stopped entirely goes red within a couple of cycles.
	 *
	 * A hook with no record at all is stale only once its interval has had
	 * time to elapse since the plugin was installed, which is what stops a
	 * freshly activated site showing three red rows before its first cron
	 * tick.
	 *
	 * @param string   $hook      Hook name.
	 * @param int      $interval  Seconds between runs, 0 if unknown.
	 * @param int      $now       Current UNIX time.
	 * @param int|null $installed When the plugin was installed.
	 * @return bool
	 */
	public static function isStale( string $hook, int $interval, int $now, ?int $installed = null ): bool {
		if ( $interval <= 0 ) {
			// A one-off job. It has no cadence to be late against, so there
			// is nothing here to judge it by.
			return false;
		}

		$tolerance = ( $interval * 2 ) + HOUR_IN_SECONDS;
		$last      = self::lastRun( $hook );

		if ( null === $last ) {
			return null !== $installed && ( $now - $installed ) > $tolerance;
		}

		return ( $now - $last ) > $tolerance;
	}

	/**
	 * Forget everything. Used by the uninstall routine.
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}
}
