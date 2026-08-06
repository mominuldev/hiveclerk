<?php
/**
 * Provider key storage tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Ai;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Ai\KeyResolver;
use Hiveclerk\Core\Support\Encryptor;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A key that cannot be read must not look like a key that works.
 *
 * `decrypt()` returns null for tampering, for rotated WordPress salts and
 * for a database restored without its own salt option — and every caller
 * reads null as "not configured". The stored mask is plaintext and keeps
 * rendering regardless, so the settings screen showed a configured
 * provider with a plausible masked key while every request using it
 * failed with an error naming the provider. Nothing anywhere said the
 * ciphertext had stopped opening.
 *
 * @internal
 */
#[CoversClass( KeyResolver::class )]
final class KeyResolverTest extends TestCase {

	/**
	 * Stand-in for the options table.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array( 'hiveclerk_encryption_salt' => str_repeat( 'c7', 32 ) );

		Functions\when( 'get_option' )->alias(
			fn( string $name, $fallback = false ) => $this->options[ $name ] ?? $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] ??= $value;

				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A working key reads as set and readable.
	 */
	public function testAStoredKeyIsSetAndReadable(): void {
		$resolver = $this->resolver();
		$resolver->store( 'anthropic', 'sk-ant-0123456789abcdef', '', '' );

		$described = $resolver->describe( 'anthropic' );

		self::assertTrue( $described['is_set'] );
		self::assertTrue( $described['is_readable'] );
		self::assertSame( 'sk-ant-0123456789abcdef', $resolver->credentials( 'anthropic' )->apiKey );
	}

	/**
	 * The regression: stored but unopenable is its own state.
	 */
	public function testAKeyThisInstallCannotOpenReadsAsSetButNotReadable(): void {
		$resolver = $this->resolver();
		$resolver->store( 'anthropic', 'sk-ant-0123456789abcdef', '', '' );

		$this->rotateTheEncryptionSalt();

		$described = $resolver->describe( 'anthropic' );

		self::assertTrue( $described['is_set'], 'ciphertext is still stored' );
		self::assertFalse( $described['is_readable'], 'but this install can no longer open it' );
	}

	/**
	 * The mask keeps rendering, which is exactly why the flag is needed.
	 */
	public function testTheMaskStillRendersForAnUnreadableKey(): void {
		$resolver = $this->resolver();
		$resolver->store( 'anthropic', 'sk-ant-0123456789abcdef', '', '' );

		$this->rotateTheEncryptionSalt();

		self::assertNotSame( '', $resolver->describe( 'anthropic' )['masked'] );
	}

	/**
	 * Nothing stored is unset, not unreadable — a distinction the screen
	 * has to make, because only one of them is a fault.
	 */
	public function testAProviderWithNoKeyIsUnsetRatherThanUnreadable(): void {
		$described = $this->resolver()->describe( 'openai' );

		self::assertFalse( $described['is_set'] );
		self::assertTrue( $described['is_readable'] );
	}

	/**
	 * Forgetting a key returns it to unset, not to broken.
	 */
	public function testForgettingAKeyLeavesNothingUnreadableBehind(): void {
		$resolver = $this->resolver();
		$resolver->store( 'anthropic', 'sk-ant-0123456789abcdef', '', '' );
		$resolver->forget( 'anthropic' );

		$described = $resolver->describe( 'anthropic' );

		self::assertFalse( $described['is_set'] );
		self::assertTrue( $described['is_readable'] );
	}

	/**
	 * Move the per-install salt, as a restored database or a salt rotation
	 * does. The ciphertext stays; the key that opens it does not.
	 *
	 * @return void
	 */
	private function rotateTheEncryptionSalt(): void {
		$this->options['hiveclerk_encryption_salt'] = str_repeat( 'a9', 32 );
	}

	/**
	 * Subject.
	 *
	 * @return KeyResolver
	 */
	private function resolver(): KeyResolver {
		return new KeyResolver(
			new Encryptor(),
			new FrozenClock( new DateTimeImmutable( '2026-08-06 12:00:00', new DateTimeZone( 'UTC' ) ) )
		);
	}
}
