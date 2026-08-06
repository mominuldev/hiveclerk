<?php
/**
 * WP-Cron queue driver tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Infrastructure\Queue;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Infrastructure\Queue\CronQueue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The driver most installs actually run on.
 *
 * Action Scheduler is not bundled — it arrives with WooCommerce or not at
 * all — so this is the fallback, and on a plain WordPress site it is the
 * only one. It had no tests.
 *
 * The behaviour worth pinning is the idempotence of `scheduleRecurring()`.
 * Every module registers its recurring job on `hiveclerk/jobs/register`,
 * which fires on every request that loads the plugin. Without the pending
 * check that is a fresh cron event per request: an events array that
 * grows without bound, and a job that runs as many times per tick as the
 * site has had page loads. It is the kind of fault that is invisible
 * until it is enormous.
 *
 * @internal
 */
#[CoversClass( CronQueue::class )]
final class CronQueueTest extends TestCase {

	/**
	 * Stand-in for the cron array: timestamp => hook => instances.
	 *
	 * @var array<int, array<string, array<string, mixed>>>
	 */
	private array $cron = array();

	/**
	 * Calls to wp_schedule_event, in order.
	 *
	 * @var array<int, array{schedule: string, hook: string}>
	 */
	private array $recurring = array();

	/**
	 * Hooks passed to wp_unschedule_hook.
	 *
	 * @var array<int, string>
	 */
	private array $unscheduled = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->cron        = array();
		$this->recurring   = array();
		$this->unscheduled = array();

		Functions\when( '_get_cron_array' )->alias( fn() => $this->cron );

		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( int $timestamp, string $hook, array $args = array() ): bool {
				$this->cron[ $timestamp ][ $hook ][ md5( serialize( $args ) ) ] = array( 'args' => $args );

				return true;
			}
		);
		Functions\when( 'wp_schedule_event' )->alias(
			function ( int $timestamp, string $schedule, string $hook, array $args = array() ): bool {
				$this->recurring[] = array(
					'schedule' => $schedule,
					'hook'     => $hook,
				);

				$this->cron[ $timestamp ][ $hook ][ md5( serialize( $args ) ) ] = array( 'args' => $args );

				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( string $hook, array $args = array() ): int|false {
				$key = md5( serialize( $args ) );

				foreach ( $this->cron as $timestamp => $hooks ) {
					if ( isset( $hooks[ $hook ][ $key ] ) ) {
						return $timestamp;
					}
				}

				return false;
			}
		);
		Functions\when( 'wp_unschedule_hook' )->alias(
			function ( string $hook ): int {
				$this->unscheduled[] = $hook;

				foreach ( array_keys( $this->cron ) as $timestamp ) {
					unset( $this->cron[ $timestamp ][ $hook ] );
				}

				return 1;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( string $hook, array $args = array() ): int {
				$key = md5( serialize( $args ) );

				foreach ( array_keys( $this->cron ) as $timestamp ) {
					unset( $this->cron[ $timestamp ][ $hook ][ $key ] );
				}

				return 1;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testItIdentifiesItselfAsTheCronDriver(): void {
		self::assertSame( 'wp-cron', ( new CronQueue() )->driver() );
	}

	/**
	 * The fault this guard exists for: modules re-register their recurring
	 * jobs on every request that boots the plugin.
	 */
	public function testSchedulingARecurringJobTwiceCreatesOneEvent(): void {
		$queue = new CronQueue();

		self::assertTrue( $queue->scheduleRecurring( 300, 'hiveclerk/jobs/sequence_tick' ) );
		self::assertTrue( $queue->scheduleRecurring( 300, 'hiveclerk/jobs/sequence_tick' ) );
		self::assertTrue( $queue->scheduleRecurring( 300, 'hiveclerk/jobs/sequence_tick' ) );

		self::assertCount( 1, $this->recurring, 'only the first call reaches WordPress' );
		self::assertSame( 1, $queue->depth() );
	}

	/**
	 * WordPress only runs the intervals it knows about, so an arbitrary
	 * one has to be mapped to the nearest registered schedule rather than
	 * passed through — a schedule name WordPress does not recognise is an
	 * event it silently never runs.
	 */
	public function testAnIntervalIsMappedToTheNearestRegisteredSchedule(): void {
		$queue = new CronQueue();

		$queue->scheduleRecurring( 280, 'hiveclerk/jobs/a' );
		$queue->scheduleRecurring( 3500, 'hiveclerk/jobs/b' );
		$queue->scheduleRecurring( 61, 'hiveclerk/jobs/c' );

		self::assertSame( 'hiveclerk_every_300', $this->recurring[0]['schedule'] );
		self::assertSame( 'hiveclerk_every_3600', $this->recurring[1]['schedule'] );
		self::assertSame( 'hiveclerk_every_60', $this->recurring[2]['schedule'] );
	}

	public function testEnqueueingSchedulesASingleEventThatIsThenPending(): void {
		$queue = new CronQueue();

		self::assertFalse( $queue->isPending( 'hiveclerk/jobs/ingest' ) );
		self::assertTrue( $queue->enqueue( 'hiveclerk/jobs/ingest', array( 'source_id' => 4 ) ) );
		self::assertTrue( $queue->isPending( 'hiveclerk/jobs/ingest', array( 'source_id' => 4 ) ) );
	}

	/**
	 * Arguments are part of the identity, so the same job for a different
	 * source is different work and must not be treated as already queued.
	 */
	public function testPendingIsCheckedPerArgumentsNotJustPerHook(): void {
		$queue = new CronQueue();

		$queue->enqueue( 'hiveclerk/jobs/ingest', array( 'source_id' => 4 ) );

		self::assertTrue( $queue->isPending( 'hiveclerk/jobs/ingest', array( 'source_id' => 4 ) ) );
		self::assertFalse( $queue->isPending( 'hiveclerk/jobs/ingest', array( 'source_id' => 9 ) ) );
	}

	/**
	 * Cancelling without arguments clears the hook entirely, which is what
	 * deactivation needs; cancelling with them removes one instance.
	 */
	public function testCancellingWithoutArgumentsClearsTheWholeHook(): void {
		$queue = new CronQueue();

		$queue->enqueue( 'hiveclerk/jobs/ingest', array( 'source_id' => 4 ) );
		$queue->enqueue( 'hiveclerk/jobs/ingest', array( 'source_id' => 9 ) );

		$queue->cancel( 'hiveclerk/jobs/ingest' );

		self::assertSame( array( 'hiveclerk/jobs/ingest' ), $this->unscheduled );
		self::assertSame( 0, $queue->depth() );
	}

	public function testCancellingWithArgumentsRemovesOnlyThatInstance(): void {
		$queue = new CronQueue();

		$queue->enqueue( 'hiveclerk/jobs/ingest', array( 'source_id' => 4 ) );
		$queue->enqueue( 'hiveclerk/jobs/ingest', array( 'source_id' => 9 ) );

		$queue->cancel( 'hiveclerk/jobs/ingest', array( 'source_id' => 4 ) );

		self::assertFalse( $queue->isPending( 'hiveclerk/jobs/ingest', array( 'source_id' => 4 ) ) );
		self::assertTrue( $queue->isPending( 'hiveclerk/jobs/ingest', array( 'source_id' => 9 ) ) );
	}

	/**
	 * Depth is what the status screen reports as the backlog, so counting
	 * another plugin's events would describe a queue that is not ours.
	 */
	public function testDepthCountsOnlyOurOwnQueuedWork(): void {
		$queue = new CronQueue();

		$queue->enqueue( 'hiveclerk/jobs/ingest', array( 'source_id' => 4 ) );
		$queue->enqueue( 'hiveclerk/jobs/embed', array( 'source_id' => 4 ) );

		// Somebody else's scheduled work, sharing the cron array.
		$this->cron[ time() + 60 ]['woocommerce_scheduled_sales']['x'] = array( 'args' => array() );

		self::assertSame( 2, $queue->depth() );
	}

	/**
	 * A site with no cron array at all must report an empty queue rather
	 * than fataling on a null.
	 */
	public function testAnEmptyCronArrayIsAnEmptyQueue(): void {
		Functions\when( '_get_cron_array' )->justReturn( false );

		$queue = new CronQueue();

		self::assertSame( 0, $queue->depth() );
		self::assertFalse( $queue->isPending( 'hiveclerk/jobs/ingest' ) );
	}
}
