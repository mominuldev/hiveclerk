<?php
/**
 * Licence entitlement tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Licence;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Core\Licence\Feature;
use Hiveclerk\Core\Licence\Licence;
use Hiveclerk\Core\Licence\LicenceStatus;
use Hiveclerk\Core\Licence\Tier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass( Licence::class )]
#[CoversClass( Tier::class )]
#[CoversClass( Feature::class )]
#[CoversClass( LicenceStatus::class )]
final class LicenceTest extends TestCase {

	public function testAnExpiredLicenceFallsBackToFreeEntitlements(): void {
		$licence = new Licence( Tier::Agency, LicenceStatus::Expired, 'HVC-…9f2a' );

		self::assertSame( Tier::Free, $licence->effectiveTier() );
		self::assertFalse( $licence->effectiveTier()->includes( Feature::Crm ) );
		// The tier bought is still reported, so the screen can say what
		// lapsed rather than pretending it was never bought.
		self::assertSame( Tier::Agency, $licence->tier );
	}

	public function testAnUnreachableServerDoesNotDowngradeTheCustomer(): void {
		// A timeout at our end, or a customer firewall, must not present
		// as a cancelled subscription.
		$licence = new Licence( Tier::Pro, LicenceStatus::Unreachable, 'HVC-…9f2a' );

		self::assertSame( Tier::Pro, $licence->effectiveTier() );
		self::assertTrue( $licence->effectiveTier()->includes( Feature::EmailSequences ) );
	}

	public function testTheFreeTierIsLimitedByScaleNotByQuality(): void {
		self::assertSame( 1, Tier::Free->clerkLimit() );
		self::assertSame( 200, Tier::Free->chunkLimit() );
		self::assertNull( Tier::Pro->clerkLimit() );
		self::assertSame( 10000, Tier::Pro->chunkLimit() );
		self::assertNull( Tier::Agency->chunkLimit() );
	}

	public function testWhiteLabelIsAgencyOnlyAndBadgeRemovalIsNot(): void {
		self::assertFalse( Tier::Pro->includes( Feature::WhiteLabel ) );
		self::assertFalse( Tier::Business->includes( Feature::WhiteLabel ) );
		self::assertTrue( Tier::Agency->includes( Feature::WhiteLabel ) );

		self::assertTrue( Tier::Pro->includes( Feature::RemoveBadge ) );
		self::assertFalse( Tier::Free->includes( Feature::RemoveBadge ) );
	}

	public function testEveryFeatureNamesTheCheapestTierThatIncludesIt(): void {
		foreach ( Feature::cases() as $feature ) {
			self::assertTrue(
				$feature->requires()->includes( $feature ),
				sprintf( '%s says it needs %s, which does not include it.', $feature->value, $feature->requires()->value )
			);
		}
	}

	public function testAnUnknownTierFallsDownRatherThanThrowing(): void {
		// A licence server that starts returning a tier this version has
		// never heard of must not fatal the customer's admin, and falling
		// down is the only safe direction to guess in.
		self::assertSame( Tier::Free, Tier::fromStorage( 'enterprise-plus' ) );
		self::assertSame( Tier::Free, Tier::fromStorage( null ) );
		self::assertSame( Tier::Business, Tier::fromStorage( 'BUSINESS' ) );
	}

	public function testDaysRemainingGoesNegativeOncePast(): void {
		$now     = new DateTimeImmutable( '2026-08-05', new DateTimeZone( 'UTC' ) );
		$licence = new Licence(
			Tier::Pro,
			LicenceStatus::Active,
			'HVC-…9f2a',
			1,
			new DateTimeImmutable( '2026-08-01', new DateTimeZone( 'UTC' ) )
		);

		self::assertSame( -4, $licence->daysRemaining( $now ) );
		self::assertNull( Licence::free()->daysRemaining( $now ) );
	}

	public function testTheWireFormCarriesBothTiersAndNeverTheKey(): void {
		$now  = new DateTimeImmutable( '2026-08-05', new DateTimeZone( 'UTC' ) );
		$wire = ( new Licence( Tier::Business, LicenceStatus::Expired, 'HVC-…9f2a' ) )->toArray( $now );

		self::assertSame( 'business', $wire['tier'] );
		self::assertSame( 'free', $wire['effective_tier'] );
		self::assertSame( 'HVC-…9f2a', $wire['masked'] );
		self::assertFalse( $wire['features']['crm'] );

		// Nothing on this object can carry a key, so nothing that renders
		// a licence can leak one.
		self::assertStringNotContainsString( 'key"', (string) wp_json_encode( $wire ) );
	}

	public function testStatusReportsWhetherItGrantsEntitlements(): void {
		self::assertTrue( LicenceStatus::Active->grantsEntitlements() );
		self::assertTrue( LicenceStatus::Unreachable->grantsEntitlements() );
		self::assertFalse( LicenceStatus::Expired->grantsEntitlements() );
		self::assertFalse( LicenceStatus::Invalid->grantsEntitlements() );
		self::assertFalse( LicenceStatus::SeatLimit->grantsEntitlements() );
	}
}
