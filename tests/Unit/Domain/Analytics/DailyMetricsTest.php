<?php
/**
 * Daily metrics tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Domain\Analytics;

use Hiveclerk\Domain\Analytics\DailyMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass( DailyMetrics::class )]
final class DailyMetricsTest extends TestCase {

	public function testDeflectionIsNullOnADayNobodyAsked(): void {
		// Not zero. Zero reads as "the clerk deflected nothing", which is
		// a judgement about a day on which it was never asked anything.
		self::assertNull( DailyMetrics::empty( '2026-08-05' )->deflectionRate() );
		self::assertNull( DailyMetrics::empty( '2026-08-05' )->costPerConversation() );
	}

	public function testDeflectionIsTheShareResolvedWithoutAPerson(): void {
		$day = new DailyMetrics(
			date: '2026-08-05',
			conversations: 10,
			resolvedByAi: 7
		);

		self::assertSame( 0.7, $day->deflectionRate() );
	}

	public function testAddingTwoDaysSumsTheirCounters(): void {
		$a = new DailyMetrics( date: '2026-08-05', conversations: 3, leadsQualified: 1, cost: 0.5 );
		$b = new DailyMetrics( date: '2026-08-05', conversations: 4, leadsQualified: 2, cost: 0.25 );

		$total = $a->plus( $b );

		self::assertSame( 7, $total->conversations );
		self::assertSame( 3, $total->leadsQualified );
		self::assertSame( 0.75, $total->cost );
	}

	public function testLatencyIsWeightedByMessageCountRatherThanAveragedAgain(): void {
		// The mean of two means is not a mean of anything. One day with
		// 100 messages at 1,000 ms and one with 1 message at 4,000 ms is
		// about 1,030 ms, not 2,500.
		$busy  = new DailyMetrics( date: '2026-08-05', messages: 100, avgLatencyMs: 1000 );
		$quiet = new DailyMetrics( date: '2026-08-05', messages: 1, avgLatencyMs: 4000 );

		self::assertSame( 1030, $busy->plus( $quiet )->avgLatencyMs );
	}

	public function testLatencyFallsBackToWhicheverSideHasOne(): void {
		$measured = new DailyMetrics( date: '2026-08-05', messages: 4, avgLatencyMs: 900 );
		$empty    = DailyMetrics::empty( '2026-08-05' );

		self::assertSame( 900, $empty->plus( $measured )->avgLatencyMs );
		self::assertSame( 900, $measured->plus( $empty )->avgLatencyMs );
	}

	public function testTheSiteWideRowIsMarkedByANullClerk(): void {
		self::assertNull( DailyMetrics::empty( '2026-08-05' )->agentId );
		self::assertSame( 4, DailyMetrics::empty( '2026-08-05', 4 )->agentId );
	}
}
