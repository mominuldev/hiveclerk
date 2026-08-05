<?php
/**
 * Date range tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Domain\Shared;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Domain\Shared\DateRange;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass( DateRange::class )]
final class DateRangeTest extends TestCase {

	public function testBothEndsAreInclusive(): void {
		$range = new DateRange( '2026-06-01', '2026-06-30' );

		// The reading a customer has when they pick "1–30 June" out of a
		// picker. An exclusive end makes every report one day short, and
		// the numbers still look like numbers.
		self::assertSame( 30, $range->days() );
		self::assertCount( 30, $range->eachDay() );
	}

	public function testLastDaysCountsTodayAsOneOfThem(): void {
		$today = new DateTimeImmutable( '2026-08-05 14:00:00', new DateTimeZone( 'UTC' ) );
		$range = DateRange::lastDays( $today, 7 );

		self::assertSame( '2026-07-30', $range->from );
		self::assertSame( '2026-08-05', $range->to );
		self::assertSame( 7, $range->days() );
	}

	public function testPreviousPeriodIsTheSameLengthAndDoesNotOverlap(): void {
		$range    = new DateRange( '2026-06-01', '2026-06-07' );
		$previous = $range->previous();

		self::assertSame( '2026-05-25', $previous->from );
		self::assertSame( '2026-05-31', $previous->to );
		self::assertSame( $range->days(), $previous->days() );
		self::assertFalse( $range->contains( $previous->to ) );
	}

	public function testRejectsAnImpossibleCalendarDate(): void {
		// Round-tripped through the parser rather than pattern-matched,
		// so 30 February is refused instead of quietly becoming 2 March.
		self::assertFalse( DateRange::isDate( '2026-02-30' ) );
		self::assertFalse( DateRange::isDate( '2026-13-01' ) );
		self::assertTrue( DateRange::isDate( '2024-02-29' ) );
	}

	public function testRejectsAnInvertedRange(): void {
		$this->expectException( InvalidArgumentException::class );

		new DateRange( '2026-06-30', '2026-06-01' );
	}

	public function testEachDayCoversQuietDaysToo(): void {
		$days = ( new DateRange( '2026-06-01', '2026-06-03' ) )->eachDay();

		self::assertSame( array( '2026-06-01', '2026-06-02', '2026-06-03' ), $days );
	}

	public function testLastDaysIsBoundedByTheMaximum(): void {
		$today = new DateTimeImmutable( '2026-08-05', new DateTimeZone( 'UTC' ) );

		self::assertSame( DateRange::MAX_DAYS, DateRange::lastDays( $today, 5000 )->days() );
	}
}
