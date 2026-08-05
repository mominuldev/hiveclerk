<?php
/**
 * Display rule tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Domain\Agent;

use Hiveclerk\Domain\Agent\DisplayRules;
use Hiveclerk\Domain\Agent\PageContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where a clerk appears, and — more importantly — where it does not.
 *
 * The expensive failures here are asymmetric. A clerk that appears on one
 * page too many is untidy; a clerk that appears over a checkout form, or
 * that silently appears nowhere after an operator saved a rule, costs
 * money in ways nobody attributes to the rule engine.
 *
 * @internal
 */
#[CoversClass( DisplayRules::class )]
#[CoversClass( PageContext::class )]
final class DisplayRulesTest extends TestCase {

	public function testNoRulesMeansEverywhere(): void {
		$rules = DisplayRules::fromArray( array() );

		$this->assertTrue( $rules->isUnrestricted() );
		$this->assertTrue( $rules->allows( new PageContext( path: '/anything/at/all' ) ) );
	}

	public function testAnIncludeListExcludesEverythingElse(): void {
		$rules = DisplayRules::fromArray( array( 'include' => array( '/products/*' ) ) );

		$this->assertTrue( $rules->allows( new PageContext( path: '/products/alpine-jacket' ) ) );
		$this->assertFalse( $rules->allows( new PageContext( path: '/about' ) ) );
	}

	public function testExclusionBeatsInclusion(): void {
		$rules = DisplayRules::fromArray(
			array(
				'include' => array( '/shop/*' ),
				'exclude' => array( '/shop/checkout*' ),
			)
		);

		$this->assertTrue( $rules->allows( new PageContext( path: '/shop/bags' ) ) );

		// The one that costs a sale when it is wrong.
		$this->assertFalse( $rules->allows( new PageContext( path: '/shop/checkout' ) ) );
		$this->assertFalse( $rules->allows( new PageContext( path: '/shop/checkout/payment' ) ) );
	}

	public function testATrailingSlashIsNotADifferentPage(): void {
		$rules = DisplayRules::fromArray( array( 'include' => array( '/pricing/' ) ) );

		$this->assertTrue( $rules->allows( new PageContext( path: '/pricing' ) ) );
		$this->assertTrue( $rules->allows( new PageContext( path: '/pricing/' ) ) );
	}

	public function testAPastedUrlIsTreatedAsItsPath(): void {
		$rules = DisplayRules::fromArray(
			array( 'include' => array( 'https://example.test/help/returns' ) )
		);

		$this->assertTrue( $rules->allows( new PageContext( path: '/help/returns' ) ) );
	}

	public function testAQueryStringIsIgnored(): void {
		$rules = DisplayRules::fromArray( array( 'exclude' => array( '/cart' ) ) );

		$this->assertFalse(
			$rules->allows( new PageContext( path: '/cart?utm_source=newsletter' ) )
		);
	}

	public function testAPatternIsNotARegularExpression(): void {
		$rules = DisplayRules::fromArray( array( 'include' => array( '/pri.e' ) ) );

		// The dot is a dot. Accepting expressions from a settings screen
		// would mean running a customer-supplied pattern on every page view.
		$this->assertFalse( $rules->allows( new PageContext( path: '/price' ) ) );
		$this->assertTrue( $rules->allows( new PageContext( path: '/pri.e' ) ) );
	}

	public function testDeviceNarrowsAndAnEmptyListDoesNot(): void {
		$mobileOnly = DisplayRules::fromArray( array( 'devices' => array( 'mobile' ) ) );

		$this->assertTrue( $mobileOnly->allows( new PageContext( device: 'mobile' ) ) );
		$this->assertFalse( $mobileOnly->allows( new PageContext( device: 'desktop' ) ) );

		// Naming every device means naming none, and is stored that way.
		$all = DisplayRules::fromArray(
			array( 'devices' => array( 'mobile', 'desktop', 'tablet' ) )
		);

		$this->assertSame( array(), $all->toArray()['devices'] );
		$this->assertTrue( $all->allows( new PageContext( device: 'desktop' ) ) );
	}

	public function testAudienceSplitsSignedInFromAnonymous(): void {
		$members = DisplayRules::fromArray( array( 'audience' => 'logged_in' ) );
		$public  = DisplayRules::fromArray( array( 'audience' => 'logged_out' ) );

		$this->assertTrue( $members->allows( new PageContext( isLoggedIn: true ) ) );
		$this->assertFalse( $members->allows( new PageContext( isLoggedIn: false ) ) );

		$this->assertTrue( $public->allows( new PageContext( isLoggedIn: false ) ) );
		$this->assertFalse( $public->allows( new PageContext( isLoggedIn: true ) ) );
	}

	public function testARoleListOnlyNarrowsSignedInVisitors(): void {
		$rules = DisplayRules::fromArray( array( 'roles' => array( 'customer' ) ) );

		$this->assertTrue(
			$rules->allows( new PageContext( isLoggedIn: true, roles: array( 'customer' ) ) )
		);
		$this->assertFalse(
			$rules->allows( new PageContext( isLoggedIn: true, roles: array( 'subscriber' ) ) )
		);

		// An anonymous visitor holds no roles, and requiring one of them
		// would be unsatisfiable — a configuration that appears nowhere for
		// a reason the screen never explains.
		$this->assertTrue( $rules->allows( new PageContext( isLoggedIn: false ) ) );
	}

	public function testAnUnknownCountryPasses(): void {
		$rules = DisplayRules::fromArray( array( 'countries' => array( 'de' ) ) );

		$this->assertTrue( $rules->allows( new PageContext( country: 'DE' ) ) );
		$this->assertFalse( $rules->allows( new PageContext( country: 'FR' ) ) );

		// Most hosts send no country header at all. Treating that as "not
		// allowed" would hide the clerk from the whole site the moment one
		// country was named.
		$this->assertTrue( $rules->allows( new PageContext( country: null ) ) );
	}

	public function testEveryTestMustPassNotJustOne(): void {
		$rules = DisplayRules::fromArray(
			array(
				'include'  => array( '/products/*' ),
				'devices'  => array( 'mobile' ),
				'audience' => 'logged_out',
			)
		);

		$this->assertTrue(
			$rules->allows( new PageContext( path: '/products/x', device: 'mobile', isLoggedIn: false ) )
		);

		// Right page, wrong device.
		$this->assertFalse(
			$rules->allows( new PageContext( path: '/products/x', device: 'desktop', isLoggedIn: false ) )
		);

		// Right page and device, wrong audience.
		$this->assertFalse(
			$rules->allows( new PageContext( path: '/products/x', device: 'mobile', isLoggedIn: true ) )
		);
	}

	/**
	 * @param mixed $junk Something an unvalidated client might send.
	 */
	#[DataProvider( 'junkValues' )]
	public function testUnusableInputIsDroppedRatherThanObeyed( mixed $junk ): void {
		$rules = DisplayRules::fromArray(
			array(
				'include'   => $junk,
				'devices'   => $junk,
				'audience'  => $junk,
				'countries' => $junk,
			)
		);

		$this->assertTrue( $rules->isUnrestricted() );
		$this->assertTrue( $rules->allows( new PageContext( path: '/anything' ) ) );
	}

	/**
	 * @return array<int, array<int, mixed>>
	 */
	public static function junkValues(): array {
		return array(
			array( null ),
			array( 'not-an-array' ),
			array( 42 ),
			array( array( array( 'nested' ) ) ),
			array( array( '' ) ),
		);
	}
}
