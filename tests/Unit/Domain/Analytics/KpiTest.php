<?php
/**
 * KPI and funnel-step tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Domain\Analytics;

use Hiveclerk\Domain\Analytics\Alert;
use Hiveclerk\Domain\Analytics\FunnelStep;
use Hiveclerk\Domain\Analytics\Kpi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass( Kpi::class )]
#[CoversClass( FunnelStep::class )]
#[CoversClass( Alert::class )]
final class KpiTest extends TestCase {

	public function testChangeIsNullWhenThereIsNothingToCompareAgainst(): void {
		// Growth from zero is not a percentage: every implementation that
		// tries reports either infinity or a plausible lie.
		self::assertNull( ( new Kpi( 'k', 'K', 12.0, 0.0 ) )->change() );
		self::assertNull( ( new Kpi( 'k', 'K', 12.0 ) )->change() );
	}

	public function testChangeIsProportionalToThePreviousPeriod(): void {
		self::assertSame( 0.25, ( new Kpi( 'k', 'K', 125.0, 100.0 ) )->change() );
		self::assertSame( -0.5, ( new Kpi( 'k', 'K', 50.0, 100.0 ) )->change() );
	}

	public function testSpendCarriesItsOwnSenseOfDirection(): void {
		$spend = new Kpi( 'cost', 'Spend', 20.0, 10.0, array(), 'currency', false );

		self::assertFalse( $spend->higherIsBetter );
		self::assertSame( 1.0, $spend->change() );
	}

	public function testAbsentChangeSerialisesAsNullRatherThanZero(): void {
		$wire = ( new Kpi( 'k', 'K', 4.0 ) )->jsonSerialize();

		self::assertNull( $wire['change'] );
		self::assertNull( $wire['previous'] );
	}

	public function testTheFirstFunnelStepHasNoConversionRate(): void {
		$step = new FunnelStep( 'conversations', 'Conversations', 1284 );

		self::assertNull( $step->rate() );
		self::assertSame( 0, $step->dropOff() );
	}

	public function testAFunnelStepConvertsFromTheOneAboveIt(): void {
		// The number an operator can act on. "31% of engaged visitors left
		// contact details" names a prompt to fix; a share of the whole
		// funnel names nothing.
		$step = new FunnelStep( 'captured', 'Captured', 231, 742 );

		self::assertEqualsWithDelta( 0.3113, (float) $step->rate(), 0.0001 );
		self::assertSame( 511, $step->dropOff() );
	}

	public function testAlertsSortUrgentFirst(): void {
		$alerts = array(
			new Alert( 'a', 'Info', null, '/x', Alert::SEVERITY_INFO ),
			new Alert( 'b', 'Urgent', null, '/y', Alert::SEVERITY_URGENT ),
			new Alert( 'c', 'Warning', null, '/z', Alert::SEVERITY_WARNING ),
		);

		usort( $alerts, static fn ( Alert $a, Alert $b ): int => $a->weight() <=> $b->weight() );

		self::assertSame( array( 'Urgent', 'Warning', 'Info' ), array_column( $alerts, 'title' ) );
	}
}
