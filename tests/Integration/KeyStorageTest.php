<?php
/**
 * Credential storage integration tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Integration;

use Hiveclerk\Ai\KeyResolver;
use Hiveclerk\Core\Support\Encryptor;
use Hiveclerk\Core\Support\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exercises the parts of credential storage that only exist inside a real
 * WordPress: the options table, and the salts the key is derived from.
 *
 * The claim being tested is the one the settings screen makes to the
 * customer — that their key is stored encrypted and never handed back —
 * so it is checked against the actual stored bytes rather than against a
 * mock that would agree with whatever the code did.
 *
 * @internal
 */
#[CoversClass( KeyResolver::class )]
#[CoversClass( Encryptor::class )]
final class KeyStorageTest extends WordPressTestCase {

	/**
	 * The option this test borrows.
	 *
	 * Named here rather than repeated as a literal, because the whole bug
	 * below was one literal in one teardown reaching further than it meant to.
	 */
	private const OPTION = 'hiveclerk_provider_keys';

	private const TEST_PROVIDER = 'anthropic';
	private const TEST_KEY      = 'sk-ant-api03-not-a-real-key-0123456789abcdef';

	/**
	 * The site's real provider keys, held while the test borrows the option.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $preserved = null;

	/**
	 * Take a copy of whatever the site already had.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( function_exists( 'get_option' ) ) {
			$existing        = get_option( self::OPTION, null );
			$this->preserved = is_array( $existing ) ? $existing : null;
		}
	}

	/**
	 * Put back exactly what was there before.
	 *
	 * ## What this used to do, and what it cost
	 *
	 * `delete_option( 'hiveclerk_provider_keys' )`. That option holds *every*
	 * provider's key, not just the one this test writes, and the integration
	 * suite runs against a real WordPress install — so `composer check`, the
	 * documented gate command, silently destroyed the site's model API keys
	 * every time it ran.
	 *
	 * It was invisible for three reasons at once. The tests passed, because
	 * they only ever assert about the key they wrote themselves. Nothing
	 * failed afterwards until something tried to *use* a provider, and the
	 * failure surfaced as "no embedding provider is configured" — a message
	 * that points at the settings screen rather than at the test suite. And
	 * an integration suite is expected to leave the database dirty, so a
	 * teardown that deletes things looks like good hygiene.
	 *
	 * Caught when a corpus that had embedded successfully an hour earlier
	 * refused to embed, with the key still verified in the admin.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( function_exists( 'delete_option' ) ) {
			if ( null === $this->preserved ) {
				delete_option( self::OPTION );
			} else {
				update_option( self::OPTION, $this->preserved, false );
			}
		}

		parent::tearDown();
	}

	public function testAStoredKeyRoundTrips(): void {
		$resolver = $this->resolver();

		$resolver->store( self::TEST_PROVIDER, self::TEST_KEY );

		$this->assertTrue( $resolver->isConfigured( self::TEST_PROVIDER ) );
		$this->assertSame(
			self::TEST_KEY,
			$resolver->credentials( self::TEST_PROVIDER )->apiKey
		);
	}

	public function testTheStoredBytesDoNotContainTheKey(): void {
		$this->resolver()->store( self::TEST_PROVIDER, self::TEST_KEY );

		$stored = get_option( 'hiveclerk_provider_keys' );
		$raw    = wp_json_encode( $stored );

		$this->assertIsString( $raw );
		// The whole point of the encryption. A database dump alone must
		// not expose the customer's provider account.
		$this->assertStringNotContainsString( self::TEST_KEY, $raw );
		$this->assertStringNotContainsString( 'not-a-real-key', $raw );
	}

	public function testTheDescriptionNeverCarriesTheKey(): void {
		$resolver = $this->resolver();
		$resolver->store( self::TEST_PROVIDER, self::TEST_KEY );

		$described = $resolver->describe( self::TEST_PROVIDER );
		$encoded   = wp_json_encode( $described );

		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( self::TEST_KEY, $encoded );

		// This is the exact payload the REST endpoint returns, so the
		// absence of a "key" field here is FR-SYS-03 holding.
		$this->assertArrayNotHasKey( 'key', $described );
		$this->assertTrue( $described['is_set'] );
		$this->assertStringContainsString( '•', (string) $described['masked'] );
	}

	public function testStoringANewKeyClearsTheOldVerification(): void {
		$resolver = $this->resolver();

		$resolver->store( self::TEST_PROVIDER, self::TEST_KEY );
		$resolver->markVerified( self::TEST_PROVIDER, 12 );

		$this->assertNotSame( '', $resolver->describe( self::TEST_PROVIDER )['verified_at'] );

		$resolver->store( self::TEST_PROVIDER, self::TEST_KEY . '-rotated' );

		// Showing a verification timestamp beside a key that was never
		// checked would claim something that did not happen.
		$this->assertSame( '', $resolver->describe( self::TEST_PROVIDER )['verified_at'] );
		$this->assertSame( 0, $resolver->describe( self::TEST_PROVIDER )['model_count'] );
	}

	public function testForgettingRemovesEverything(): void {
		$resolver = $this->resolver();

		$resolver->store( self::TEST_PROVIDER, self::TEST_KEY );
		$resolver->forget( self::TEST_PROVIDER );

		$this->assertFalse( $resolver->isConfigured( self::TEST_PROVIDER ) );
		$this->assertFalse( $resolver->credentials( self::TEST_PROVIDER )->isPresent() );
	}

	public function testTamperedCiphertextReadsAsUnconfigured(): void {
		$resolver = $this->resolver();
		$resolver->store( self::TEST_PROVIDER, self::TEST_KEY );

		$stored = get_option( 'hiveclerk_provider_keys' );
		$this->assertIsArray( $stored );

		// Flip the tail of the ciphertext. GCM is authenticated, so this
		// must fail to decrypt rather than yield altered plaintext.
		$stored[ self::TEST_PROVIDER ]['key'] = substr(
			(string) $stored[ self::TEST_PROVIDER ]['key'],
			0,
			-6
		) . 'AAAAAA';

		update_option( 'hiveclerk_provider_keys', $stored, false );

		$this->assertFalse( $this->resolver()->credentials( self::TEST_PROVIDER )->isPresent() );
	}

	public function testCredentialsRefuseToBeSerialised(): void {
		$this->resolver()->store( self::TEST_PROVIDER, self::TEST_KEY );

		$credentials = $this->resolver()->credentials( self::TEST_PROVIDER );

		$this->expectException( \LogicException::class );

		// A credential reaching a transient or a queued job payload is the
		// failure this prevents.
		serialize( $credentials );
	}

	public function testCredentialsRefuseToBeEncodedAsJson(): void {
		$this->resolver()->store( self::TEST_PROVIDER, self::TEST_KEY );

		$credentials = $this->resolver()->credentials( self::TEST_PROVIDER );

		// `__sleep()` covers serialize() and everything built on it, and
		// nothing at all for wp_json_encode(), which reads the public
		// properties straight off the object. One of these reaching a REST
		// response or a debug log would have carried the key in plain text.
		$this->expectException( \LogicException::class );

		wp_json_encode( $credentials );
	}

	/**
	 * A resolver with no cached state.
	 *
	 * @return KeyResolver
	 */
	private function resolver(): KeyResolver {
		return new KeyResolver( new Encryptor(), new SystemClock() );
	}
}
