<?php
/**
 * Action Scheduler queue driver.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Infrastructure\Queue;

use Hiveclerk\Core\Queue\QueueInterface;

/**
 * Runs jobs through Action Scheduler.
 *
 * The preferred driver, and the reason is retries: Action Scheduler
 * stores each action in its own table with a status, re-runs failures
 * with backoff, limits concurrency, and provides an admin screen where a
 * stuck job can actually be seen. WP-Cron has none of that.
 *
 * It is not bundled. Action Scheduler ships inside WooCommerce and a long
 * list of other plugins, and every copy negotiates which version loads —
 * so on a large share of target sites it is already present, and adding
 * our own copy would only add weight to the sites that do not need it.
 * Where it is genuinely absent, CronQueue takes over and says so.
 */
final class ActionSchedulerQueue implements QueueInterface {

	/**
	 * Whether Action Scheduler is loaded on this site.
	 *
	 * @return bool
	 */
	public static function isAvailable(): bool {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Run something as soon as possible.
	 *
	 * @param string               $hook Hook name.
	 * @param array<string, mixed> $args Arguments.
	 * @return bool
	 */
	public function enqueue( string $hook, array $args = array() ): bool {
		return as_enqueue_async_action( $hook, array( $args ), self::GROUP ) > 0;
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
		return as_schedule_single_action( $timestamp, $hook, array( $args ), self::GROUP ) > 0;
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

		return as_schedule_recurring_action(
			time() + $interval,
			$interval,
			$hook,
			array( $args ),
			self::GROUP
		) > 0;
	}

	/**
	 * Cancel pending work.
	 *
	 * @param string               $hook Hook name.
	 * @param array<string, mixed> $args Arguments.
	 * @return void
	 */
	public function cancel( string $hook, array $args = array() ): void {
		as_unschedule_all_actions( $hook, array() === $args ? array() : array( $args ), self::GROUP );
	}

	/**
	 * Whether matching work is already pending.
	 *
	 * @param string               $hook Hook name.
	 * @param array<string, mixed> $args Arguments.
	 * @return bool
	 */
	public function isPending( string $hook, array $args = array() ): bool {
		return as_has_scheduled_action(
			$hook,
			array() === $args ? null : array( $args ),
			self::GROUP
		);
	}

	/**
	 * How many of our jobs are waiting.
	 *
	 * @return int
	 */
	public function depth(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return -1;
		}

		return count(
			as_get_scheduled_actions(
				array(
					'group'    => self::GROUP,
					'status'   => 'pending',
					'per_page' => -1,
				),
				'ids'
			)
		);
	}

	/**
	 * Driver name.
	 *
	 * @return string
	 */
	public function driver(): string {
		return 'action-scheduler';
	}
}
