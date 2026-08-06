<?php
/**
 * Job runner tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Queue;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Core\Queue\JobRegistry;
use Hiveclerk\Tests\Support\Queue\FailingProbeJob;
use Hiveclerk\Tests\Support\Queue\SucceedingProbeJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The single choke point every background job funnels through.
 *
 * Both queue drivers call into here, which makes it the one place that
 * can answer "was this job reached" — the question the R-2 host findings
 * turned on. On Hostinger the web SAPI and the CLI ran different PHP
 * versions, so cron fired hooks the plugin was not loaded for: every job
 * silently stopped, the schedule stayed immaculate, and the status screen
 * read the schedule.
 *
 * Two behaviours here are load-bearing and neither had a test.
 *
 * **The heartbeat is written before the work.** A job killed by the
 * memory or execution limit never reaches a line after `handle()`, and
 * those are exactly the jobs an operator most needs evidence of. Recorded
 * after, they would be indistinguishable from jobs nothing ever called —
 * and the two have opposite fixes.
 *
 * **A throw is contained.** On WP-Cron the job runs inside a visitor's
 * page load, so an uncaught exception is the visitor's error, not the
 * operator's.
 *
 * @internal
 */
#[CoversClass( JobRegistry::class )]
final class JobRegistryTest extends TestCase {

	/**
	 * Hooks attached by boot(), and the callback for each.
	 *
	 * @var array<string, callable>
	 */
	private array $hooks = array();

	/**
	 * Heartbeat writes, in order: [hook, failed].
	 *
	 * @var array<int, array{0: string, 1: bool}>
	 */
	private array $beats = array();

	/**
	 * Actions fired.
	 *
	 * @var array<int, string>
	 */
	private array $fired = array();

	/**
	 * Stand-in for the options table, which the heartbeat writes to.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->hooks   = array();
		$this->beats   = array();
		$this->fired   = array();
		$this->options = array();

		Functions\when( 'add_action' )->alias(
			function ( string $hook, callable $callback ): bool {
				$this->hooks[ $hook ] = $callback;

				return true;
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( string $hook ): void {
				$this->fired[] = $hook;
			}
		);

		// The heartbeat is a real collaborator here rather than a mock:
		// the ordering under test is "recorded before handle()", and a
		// stub that recorded nothing could not show it.
		Functions\when( 'get_option' )->alias(
			fn( string $name, $fallback = false ) => $this->options[ $name ] ?? $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] = $value;

				// Every write is one heartbeat; the shape tells us which.
				foreach ( is_array( $value ) ? $value : array() as $hook => $record ) {
					$this->beats[] = array( (string) $hook, ( $record['failed_at'] ?? 0 ) > 0 );
				}

				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testEveryRegisteredJobIsAttachedToItsOwnHook(): void {
		$registry = new JobRegistry();
		$registry->add( new SucceedingProbeJob() );
		$registry->add( new FailingProbeJob() );
		$registry->boot();

		self::assertSame(
			array( SucceedingProbeJob::hook(), FailingProbeJob::hook() ),
			$registry->hooks()
		);
		self::assertArrayHasKey( SucceedingProbeJob::hook(), $this->hooks );
		self::assertArrayHasKey( FailingProbeJob::hook(), $this->hooks );
	}

	/**
	 * A job that fatals must still have left evidence it was reached.
	 */
	public function testTheHeartbeatIsWrittenBeforeTheWorkNotAfter(): void {
		$job = new SucceedingProbeJob();

		$registry = new JobRegistry();
		$registry->add( $job );
		$registry->boot();

		( $this->hooks[ SucceedingProbeJob::hook() ] )( array() );

		self::assertTrue( $job->ran );
		self::assertSame(
			array( array( SucceedingProbeJob::hook(), false ) ),
			$this->beats,
			'exactly one heartbeat, not marked failed'
		);
		self::assertTrue(
			$job->beatsWhenHandleRan >= 1,
			'the heartbeat must already be on record by the time handle() runs'
		);
	}

	/**
	 * On WP-Cron this runs inside a visitor's page load.
	 */
	public function testAThrowingJobDoesNotEscapeTheRunner(): void {
		$registry = new JobRegistry();
		$registry->add( new FailingProbeJob() );
		$registry->boot();

		( $this->hooks[ FailingProbeJob::hook() ] )( array() );

		self::assertContains( 'hiveclerk/job/failed', $this->fired );
	}

	/**
	 * "Reachable and broken" and "nothing is calling it" have opposite
	 * fixes, so a failure is recorded as a second, distinct beat.
	 */
	public function testAFailureIsRecordedSeparatelyFromTheAttempt(): void {
		$registry = new JobRegistry();
		$registry->add( new FailingProbeJob() );
		$registry->boot();

		( $this->hooks[ FailingProbeJob::hook() ] )( array() );

		self::assertCount( 2, $this->beats, 'the attempt, then the failure' );
		self::assertFalse( $this->beats[0][1], 'the attempt is not marked failed' );
		self::assertTrue( $this->beats[1][1], 'the failure is' );
	}

	/**
	 * Arguments arrive from storage, serialised possibly weeks ago by a
	 * previous version. A non-array must not reach `handle()`.
	 */
	public function testMalformedStoredArgumentsAreNormalisedToAnArray(): void {
		$job = new SucceedingProbeJob();

		$registry = new JobRegistry();
		$registry->add( $job );
		$registry->boot();

		( $this->hooks[ SucceedingProbeJob::hook() ] )( 'not-an-array' );

		self::assertSame( array(), $job->received );
	}

	public function testArgumentsAreHandedToTheJobUnchanged(): void {
		$job = new SucceedingProbeJob();

		$registry = new JobRegistry();
		$registry->add( $job );
		$registry->boot();

		( $this->hooks[ SucceedingProbeJob::hook() ] )( array( 'source_id' => 7 ) );

		self::assertSame( array( 'source_id' => 7 ), $job->received );
	}

	/**
	 * Registering the same hook twice replaces rather than duplicates, so
	 * a module booted twice cannot run its work twice per tick.
	 */
	public function testRegisteringTheSameHookTwiceKeepsOneJob(): void {
		$registry = new JobRegistry();
		$registry->add( new SucceedingProbeJob() );
		$registry->add( new SucceedingProbeJob() );

		self::assertCount( 1, $registry->hooks() );
	}
}
