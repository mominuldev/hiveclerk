<?php
/**
 * Address hashing tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Privacy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Core\Privacy\IpHasher;
use Hiveclerk\Core\Privacy\PrivacySettings;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * An IP hash is only a hash if it is salted.
 *
 * This read the `AUTH_SALT` constant directly and fell back to an empty
 * string when it was not defined — a state some migrations and
 * mis-provisioned hosts leave behind, and one nothing reported. The IPv4
 * space is four billion entries, so an unsalted SHA-256 of an address is
 * enumerable end to end: a reversible identifier wearing a hash's
 * clothes, which is the phrase the class itself uses for what it must not
 * produce. `wp_salt()` cannot return empty, because core generates and
 * stores a per-install value when the constants are absent.
 *
 * @internal
 */
#[CoversClass( IpHasher::class )]
final class IpHasherTest extends TestCase {

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
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'a-per-install-secret' );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The regression: a digest anyone can rebuild from an address alone.
	 */
	public function testTheDigestIsSaltedAndNotABareHashOfTheAddress(): void {
		$hash = $this->hasher()->hash();

		self::assertNotNull( $hash );
		self::assertNotSame( hash( 'sha256', '203.0.113.7' ), $hash );
		self::assertNotSame( hash( 'sha256', '|203.0.113.7' ), $hash );
		self::assertSame( hash( 'sha256', 'a-per-install-secret|203.0.113.7' ), $hash );
	}

	/**
	 * Change the site's secret and every digest must change with it.
	 */
	public function testADifferentInstallProducesADifferentDigest(): void {
		$first = $this->hasher()->hash();

		Functions\when( 'wp_salt' )->justReturn( 'a-different-install' );

		self::assertNotSame( $first, $this->hasher()->hash() );
	}

	/**
	 * The setting still wins over everything else.
	 */
	public function testNothingIsReturnedWhenTheSiteHasStorageOff(): void {
		$this->options['hiveclerk_settings'] = array( 'privacy' => array( 'store_ip_hash' => false ) );

		self::assertNull( $this->hasher()->hash() );
	}

	/**
	 * No address at all — WP-CLI, cron — is not an error.
	 */
	public function testAnAbsentAddressIsNotHashed(): void {
		unset( $_SERVER['REMOTE_ADDR'] );

		self::assertNull( $this->hasher()->hash() );
	}

	/**
	 * Nor is something that is not an address.
	 */
	public function testAMalformedAddressIsNotHashed(): void {
		$_SERVER['REMOTE_ADDR'] = 'not-an-address';

		self::assertNull( $this->hasher()->hash() );
	}

	/**
	 * Subject.
	 *
	 * @return IpHasher
	 */
	private function hasher(): IpHasher {
		return new IpHasher(
			new PrivacySettings(
				new SettingsRepository(),
				new FrozenClock( new DateTimeImmutable( '2026-08-06 12:00:00', new DateTimeZone( 'UTC' ) ) )
			)
		);
	}
}
