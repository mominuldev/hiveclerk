<?php
/**
 * Job heartbeat tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Queue;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Core\Queue\JobHeartbeat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The state these tests describe was measured on a real host, not imagined.
 *
 * On Hostinger the web SAPI and the CLI run different PHP versions. With
 * only the web one raised to the 8.3 this plugin requires, a system cron
 * calling `wp-cron.php` runs under a PHP the plugin refuses to boot on:
 * `has_action()` is false for every recurring hook while
 * `wp cron event list` shows all of them with a healthy next run, because
 * rescheduling happens whether or not a callback existed.
 *
 * @internal
 */
#[CoversClass( JobHeartbeat::class )]
final class JobHeartbeatTest extends TestCase {

	/**
	 * Stand-in for the options table.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();

		Functions\when( 'get_option' )->alias(
			fn( string $name, $fallback = false ) => $this->options[ $name ] ?? $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ) {
				unset( $this->options[ $name ] );

				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testAJobThatHasNeverRunOnAnOldInstallIsStale(): void {
		$now       = 1800000000;
		$installed = $now - ( 30 * DAY_IN_SECONDS );

		// The exact Hostinger state: scheduled since installation, never
		// once answered, and nothing in the schedule saying so.
		self::assertTrue( JobHeartbeat::isStale( 'hiveclerk/jobs/sequence_tick', 300, $now, $installed ) );
	}

	public function testAFreshInstallDoesNotShowRedBeforeItsFirstTick(): void {
		$now = 1800000000;

		// Installed ninety seconds ago, five-minute job. Nothing is wrong
		// yet, and three red rows on a site somebody just set up would
		// teach them to ignore this screen.
		self::assertFalse( JobHeartbeat::isStale( 'hiveclerk/jobs/sequence_tick', 300, $now, $now - 90 ) );
	}

	public function testAQuietSiteRunningLateIsNotStale(): void {
		$now = 1800000000;
		JobHeartbeat::record( 'hiveclerk/jobs/sequence_tick', $now - 600 );

		// WP-Cron fires on traffic. A five-minute job that last ran ten
		// minutes ago on a site nobody visited is late, not broken.
		self::assertFalse( JobHeartbeat::isStale( 'hiveclerk/jobs/sequence_tick', 300, $now, null ) );
	}

	public function testAJobThatStoppedEntirelyGoesStale(): void {
		$now = 1800000000;
		JobHeartbeat::record( 'hiveclerk/jobs/sequence_tick', $now - DAY_IN_SECONDS );

		self::assertTrue( JobHeartbeat::isStale( 'hiveclerk/jobs/sequence_tick', 300, $now, null ) );
	}

	public function testAOneOffJobIsNeverStale(): void {
		$now = 1800000000;

		// No cadence to be late against.
		self::assertFalse( JobHeartbeat::isStale( 'hiveclerk/job/sync_lead', 0, $now, $now - ( 365 * DAY_IN_SECONDS ) ) );
	}

	public function testARunAndAFailedRunAreRecordedSeparately(): void {
		$now = 1800000000;

		JobHeartbeat::record( 'hiveclerk/jobs/analytics_rollup', $now );
		$all = JobHeartbeat::all();
		self::assertSame( $now, $all['hiveclerk/jobs/analytics_rollup']['ran_at'] );
		self::assertSame( 0, $all['hiveclerk/jobs/analytics_rollup']['failed_at'] );

		JobHeartbeat::record( 'hiveclerk/jobs/analytics_rollup', $now + 60, true );
		$all = JobHeartbeat::all();

		/*
		 * The distinction that matters operationally: a job failing every
		 * run is reachable and broken; a job nothing is calling is not
		 * reachable at all. Collapsing them hides the second behind the
		 * first, and they have completely different fixes.
		 */
		self::assertSame( $now + 60, $all['hiveclerk/jobs/analytics_rollup']['ran_at'] );
		self::assertSame( $now + 60, $all['hiveclerk/jobs/analytics_rollup']['failed_at'] );
		self::assertFalse( JobHeartbeat::isStale( 'hiveclerk/jobs/analytics_rollup', 3600, $now + 60, null ) );
	}

	public function testAMalformedStoredValueDoesNotBreakReading(): void {
		$this->options[ JobHeartbeat::OPTION ] = 'not an array';
		self::assertSame( array(), JobHeartbeat::all() );

		$this->options[ JobHeartbeat::OPTION ] = array( 'hook' => 'also not an array' );
		self::assertSame( array(), JobHeartbeat::all() );

		$this->options[ JobHeartbeat::OPTION ] = array( 'hook' => array( 'ran_at' => 'nonsense' ) );
		self::assertSame( 0, JobHeartbeat::all()['hook']['ran_at'] );
		self::assertNull( JobHeartbeat::lastRun( 'hook' ) );
	}

	public function testForgettingRemovesEverything(): void {
		JobHeartbeat::record( 'hiveclerk/jobs/sequence_tick', 1800000000 );
		JobHeartbeat::forget();

		self::assertSame( array(), JobHeartbeat::all() );
		self::assertNull( JobHeartbeat::lastRun( 'hiveclerk/jobs/sequence_tick' ) );
	}
}
