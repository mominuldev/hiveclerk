<?php
/**
 * Rollup scheduling tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Analytics;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Domain\Analytics\DailyMetrics;
use Hiveclerk\Modules\Analytics\Services\RollupService;
use Hiveclerk\Tests\Support\Analytics\InMemoryRollups;
use Hiveclerk\Tests\Support\Analytics\RecordingRollupSource;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Which days the rollup processes, and which it leaves alone.
 *
 * Every test here is a version of one question: can a figure the customer
 * reads be silently low? Three ways it could be — a day never counted, a
 * day counted once and never revisited, and today counted as if it were
 * finished — and each has a test.
 *
 * @internal
 */
#[CoversClass( RollupService::class )]
final class RollupServiceTest extends TestCase {

	private InMemoryRollups $rollups;

	private FrozenClock $clock;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'get_option'    => static fn (): array => array(),
				'update_option' => true,
				'do_action'     => null,
				'apply_filters' => static fn ( string $hook, $value ) => $value,
			)
		);

		$this->rollups = new InMemoryRollups();
		$this->clock   = new FrozenClock(
			new DateTimeImmutable( '2026-08-05 09:00:00', new DateTimeZone( 'UTC' ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testAFreshSiteBackfillsFromItsFirstConversation(): void {
		$source = new RecordingRollupSource( '2026-08-01' );
		$result = $this->service( $source )->run();

		// The 1st to the 4th. Today is never stored, and yesterday is the
		// last finished day.
		self::assertSame(
			array( '2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04' ),
			$source->asked
		);
		self::assertSame( 4, $result['processed'] );
		self::assertSame( 0, $result['remaining'] );
	}

	public function testACaughtUpSiteRevisitsATrailingWindow(): void {
		// A rating left this morning belongs to yesterday's conversation.
		// Sealing a day at midnight would leave it permanently uncounted,
		// and nothing would report it.
		$this->rollups->put( new DailyMetrics( '2026-08-04' ) );

		$source = new RecordingRollupSource( '2026-01-01' );
		$this->service( $source )->run();

		self::assertCount( RollupService::REPROCESS_DAYS, $source->asked );
		self::assertSame( '2026-07-29', $source->asked[0] );
		self::assertSame( '2026-08-04', $source->asked[ count( $source->asked ) - 1 ] );
	}

	public function testABacklogIsDrainedInBatchesRatherThanInOneRequest(): void {
		$source = new RecordingRollupSource( '2025-08-05' );
		$result = $this->service( $source )->run();

		self::assertSame( RollupService::BATCH_DAYS, $result['processed'] );
		self::assertGreaterThan( 0, $result['remaining'] );
		self::assertCount( RollupService::BATCH_DAYS, $source->asked );
	}

	public function testASecondRunPicksUpWhereTheFirstStopped(): void {
		$source  = new RecordingRollupSource( '2026-06-01' );
		$service = $this->service( $source );

		$first = $service->run();
		$last  = $this->rollups->lastRolledUp();

		$source->asked = array();
		$service->run();

		self::assertGreaterThan( 0, $first['processed'] );
		self::assertNotNull( $last );
		// Forward, not over the same days again: while a site is behind,
		// re-doing settled days only slows the catch-up down.
		self::assertGreaterThan( $last, $source->asked[0] );
	}

	public function testASiteWithNoConversationsIsNotFilledWithZeroes(): void {
		$source = new RecordingRollupSource( null );
		$result = $this->service( $source )->run();

		self::assertSame( array(), $source->asked );
		self::assertSame( 0, $result['processed'] );
		self::assertSame( array(), $this->rollups->rows );
	}

	public function testTodayIsCountedLiveAndNeverStored(): void {
		$source  = new RecordingRollupSource( '2026-08-01' );
		$service = $this->service( $source );

		$service->run();

		foreach ( $this->rollups->rows as $metrics ) {
			self::assertNotSame( '2026-08-05', $metrics->date, 'Today must not be stored.' );
		}

		self::assertSame( '2026-08-05', $service->today()->date );
	}

	public function testTheQualifyingScoreComesFromTheCustomersOwnBands(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'leads' => array( 'bands' => array( 'qualified' => 60 ) ) )
		);

		$source = new RecordingRollupSource( '2026-08-04' );
		$this->service( $source )->run();

		self::assertSame( 60, $source->qualifiedScore );
	}

	public function testTheDefaultQualifyingScoreIsUsedWhenNoneIsConfigured(): void {
		$source = new RecordingRollupSource( '2026-08-04' );
		$this->service( $source )->run();

		self::assertSame( 75, $source->qualifiedScore );
	}

	/**
	 * A service wired to the fakes.
	 *
	 * @param RecordingRollupSource $source Source.
	 * @return RollupService
	 */
	private function service( RecordingRollupSource $source ): RollupService {
		return new RollupService(
			$source,
			$this->rollups,
			new SettingsRepository(),
			$this->clock
		);
	}
}
