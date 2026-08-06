<?php
/**
 * Secret encryption tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Core\Support\Encryptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The defect these tests exist for was a fatal, not a leak.
 *
 * `v1` derived the encryption key with the WordPress salts as HKDF key
 * material. `hash_hkdf()` rejects an empty key, so an install with no
 * salts defined — the state some migrations and mis-provisioned hosts
 * leave behind — threw an uncaught `ValueError` on every read and write of
 * a provider key. `v2` swaps the arguments so the key material is the
 * per-install salt, which is generated on demand and cannot be empty.
 *
 * That specific case cannot be asserted here: the salts are PHP constants
 * defined by the test bootstrap and a constant cannot be undefined inside
 * a running process. What is asserted is the property the fix rests on —
 * both versions decrypt, only `v2` is written — so a change that dropped
 * the legacy path would strand every already-stored key and fail here.
 *
 * @internal
 */
#[CoversClass( Encryptor::class )]
final class EncryptorTest extends TestCase {

	/**
	 * A fixed per-install salt, so the legacy key can be recomputed here.
	 */
	private const SALT = 'ab54c1f0e7d29b3a6c8145f0937bde12ab54c1f0e7d29b3a6c8145f0937bde12';

	/**
	 * Stand-in for the options table.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Subject.
	 *
	 * @var Encryptor
	 */
	private Encryptor $encryptor;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array( 'hiveclerk_encryption_salt' => self::SALT );

		Functions\when( 'get_option' )->alias(
			fn( string $name, $fallback = false ) => $this->options[ $name ] ?? $fallback
		);
		Functions\when( 'add_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] ??= $value;

				return true;
			}
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

		$this->encryptor = new Encryptor();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The point of the class.
	 */
	public function testARoundTripReturnsTheSecret(): void {
		$secret = 'sk-live-4f9a2c7e1b6d8035';

		self::assertSame( $secret, $this->encryptor->decrypt( $this->encryptor->encrypt( $secret ) ) );
	}

	/**
	 * New writes must carry the new version, or nothing ever upgrades.
	 */
	public function testCiphertextCarriesTheCurrentVersion(): void {
		self::assertStringStartsWith( 'v2:', $this->encryptor->encrypt( 'sk-live-4f9a2c7e1b6d' ) );
	}

	/**
	 * The whole reason no migration is needed.
	 */
	public function testALegacyCiphertextStillDecrypts(): void {
		$secret = 'sk-legacy-8823ffa10cd4';

		self::assertSame( $secret, $this->encryptor->decrypt( $this->legacyCiphertext( $secret ) ) );
	}

	/**
	 * GCM is authenticated and a flipped byte must not read as plaintext.
	 */
	public function testTamperedCiphertextReadsAsUnreadable(): void {
		$payload = $this->encryptor->encrypt( 'sk-live-4f9a2c7e1b6d' );
		$body    = substr( $payload, 3 );
		$binary  = (string) base64_decode( $body, true );

		$binary[ strlen( $binary ) - 1 ] = 'X' === $binary[ strlen( $binary ) - 1 ] ? 'Y' : 'X';

		self::assertNull( $this->encryptor->decrypt( 'v2:' . base64_encode( $binary ) ) );
	}

	/**
	 * A version this build cannot read is unreadable, not a fatal.
	 */
	public function testAnUnknownVersionReadsAsUnreadable(): void {
		self::assertNull( $this->encryptor->decrypt( 'v9:' . base64_encode( 'whatever' ) ) );
	}

	/**
	 * A reused IV would make identical secrets recognisable in a dump.
	 */
	public function testTheSameSecretEncryptsDifferentlyEachTime(): void {
		$secret = 'sk-live-4f9a2c7e1b6d';

		self::assertNotSame( $this->encryptor->encrypt( $secret ), $this->encryptor->encrypt( $secret ) );
	}

	/**
	 * The mask was the key: seven leading plus four trailing characters
	 * covers the whole of anything shorter than twelve.
	 */
	public function testAShortSecretIsNotRevealedByItsMask(): void {
		$secret = 'sk-12345678';

		self::assertSame( 11, strlen( $secret ) );
		self::assertStringNotContainsString( $secret, $this->encryptor->mask( $secret ) );
		self::assertSame( '••••••••', $this->encryptor->mask( $secret ) );
	}

	/**
	 * A short secret's length is itself a hint, so it is not shown.
	 */
	public function testAShortMaskDoesNotDiscloseTheLength(): void {
		self::assertSame(
			$this->encryptor->mask( 'abcd' ),
			$this->encryptor->mask( 'abcdefghijk' )
		);
	}

	/**
	 * A real key still shows enough of itself to be recognised.
	 */
	public function testALongSecretKeepsItsEndsVisible(): void {
		$masked = $this->encryptor->mask( 'sk-live-4f9a2c7e1b6d8035' );

		self::assertStringStartsWith( 'sk-live', $masked );
		self::assertStringEndsWith( '8035', $masked );
		self::assertStringNotContainsString( '4f9a2c7e1b6d', $masked );
	}

	/**
	 * Build a ciphertext the way the previous version did.
	 *
	 * @param string $plaintext Secret.
	 * @return string
	 */
	private function legacyCiphertext( string $plaintext ): string {
		$salts = ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '' )
			. ( defined( 'LOGGED_IN_KEY' ) ? (string) LOGGED_IN_KEY : '' );

		$key = hash_hkdf( 'sha256', $salts, 32, 'hiveclerk-secret-v1', self::SALT );
		$iv  = random_bytes( (int) openssl_cipher_iv_length( 'aes-256-gcm' ) );
		$tag = '';

		$ciphertext = (string) openssl_encrypt(
			$plaintext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16
		);

		return 'v1:' . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * The property the whole rotation design rests on.
	 *
	 * Changing the salt makes every stored secret unreadable at the instant
	 * it changes. If this fails, "rotate the key" means "lose every provider
	 * key" — and silently, because unreadable reads as "not configured"
	 * everywhere in this plugin.
	 */
	public function testASecretStaysReadableAcrossTheWindow(): void {
		$ciphertext = $this->encryptor->encrypt( 'sk-live-4f9a2c7e1b6d8035' );

		self::assertTrue( $this->encryptor->beginRotation() );

		self::assertSame( 'sk-live-4f9a2c7e1b6d8035', $this->encryptor->decrypt( $ciphertext ) );
	}

	public function testTheSaltActuallyChanged(): void {
		$before = $this->options['hiveclerk_encryption_salt'];

		$this->encryptor->beginRotation();

		// The control for the test above. If the salt never moved, "still
		// readable" would be true for the least interesting reason.
		self::assertNotSame( $before, $this->options['hiveclerk_encryption_salt'] );
		self::assertSame( $before, $this->options['hiveclerk_encryption_salt_previous'] );
	}

	public function testASecretWrittenBeforeTheWindowIsNotCurrent(): void {
		$ciphertext = $this->encryptor->encrypt( 'sk-live-4f9a2c7e1b6d8035' );

		$this->encryptor->beginRotation();

		// This is what tells a sweep there is work to do.
		self::assertFalse( $this->encryptor->isCurrent( $ciphertext ) );
		self::assertTrue( $this->encryptor->isCurrent( $this->encryptor->encrypt( 'anything' ) ) );
	}

	public function testASecondRotationIsRefused(): void {
		self::assertTrue( $this->encryptor->beginRotation() );

		/*
		 * Two salts is all we hold. A second rotation would push the oldest
		 * off the end and strand everything not yet rewritten under the
		 * first one — permanently, and without saying so.
		 */
		self::assertFalse( $this->encryptor->beginRotation() );
	}

	public function testClosingTheWindowMakesTheOldKeyWorthless(): void {
		$stranded = $this->encryptor->encrypt( 'sk-live-never-rewritten' );

		$this->encryptor->beginRotation();

		$rewritten = $this->encryptor->encrypt( (string) $this->encryptor->decrypt( $stranded ) );

		$this->encryptor->finishRotation();

		// The point of rotating: what the retired key protected can no
		// longer be read with it.
		self::assertNull( $this->encryptor->decrypt( $stranded ) );

		// And what the sweep moved is still there.
		self::assertSame( 'sk-live-never-rewritten', $this->encryptor->decrypt( $rewritten ) );
	}

	public function testRotationStateIsReported(): void {
		self::assertFalse( $this->encryptor->isRotating() );

		$this->encryptor->beginRotation();
		self::assertTrue( $this->encryptor->isRotating() );

		$this->encryptor->finishRotation();
		self::assertFalse( $this->encryptor->isRotating() );
	}

	public function testALegacyValueAlsoSurvivesTheWindow(): void {
		$legacy = $this->legacyCiphertext( 'sk-live-written-under-v1' );

		$this->encryptor->beginRotation();

		/*
		 * v1 derives with the per-install salt as its HKDF *salt*, so
		 * rotating strands v1 exactly as it strands v2. Easy to miss,
		 * because the version prefix makes it look like a separate path
		 * that the new salt does not touch.
		 */
		self::assertSame( 'sk-live-written-under-v1', $this->encryptor->decrypt( $legacy ) );
	}
}
