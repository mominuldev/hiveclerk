<?php
/**
 * Licence response authenticity tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Licence;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Core\Licence\LicenceSignature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the verifier actually refuses things, rather than returning true
 * everywhere and looking like a control.
 *
 * The keypair is generated per test rather than fixed, so a passing run
 * cannot be the result of a hard-coded signature agreeing with a hard-coded
 * body.
 *
 * @internal
 */
#[CoversClass( LicenceSignature::class )]
final class LicenceSignatureTest extends TestCase {

	/**
	 * Raw signing key for the test server.
	 *
	 * @var string
	 */
	private string $secret = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$pair = sodium_crypto_sign_keypair();

		$this->secret = sodium_crypto_sign_secretkey( $pair );
		$public       = base64_encode( sodium_crypto_sign_publickey( $pair ) );

		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data, $flags = 0 ) => json_encode( $data, (int) $flags ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => 'hiveclerk/licence/public_key' === $hook ? $public : $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testAGenuineAnswerVerifies(): void {
		$body = $this->sign(
			array(
				'status' => 'active',
				'tier'   => 'business',
				'sites'  => 2,
			)
		);

		self::assertTrue( LicenceSignature::verify( $body, time() ) );
		self::assertTrue( LicenceSignature::isConfigured() );
	}

	public function testATamperedTierIsRejected(): void {
		$body = $this->sign(
			array(
				'status' => 'active',
				'tier'   => 'pro',
				'sites'  => 1,
			)
		);

		// The single field an attacker in the middle would most want to
		// change, and the whole reason this class exists.
		$body['tier'] = 'agency';

		self::assertFalse( LicenceSignature::verify( $body, time() ) );
	}

	public function testAnUnsignedAnswerIsRejectedOnceAKeyIsConfigured(): void {
		$body = array(
			'status'    => 'active',
			'tier'      => 'agency',
			'signed_at' => time(),
		);

		// Stripping the signature must not be a way to skip the check.
		self::assertFalse( LicenceSignature::verify( $body, time() ) );
	}

	public function testAnAnswerSignedByADifferentServerIsRejected(): void {
		$other = sodium_crypto_sign_keypair();
		$body  = array(
			'status'    => 'active',
			'tier'      => 'agency',
			'signed_at' => time(),
		);

		$payload = $body;
		ksort( $payload );
		$body['signature'] = base64_encode(
			sodium_crypto_sign_detached(
				(string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				sodium_crypto_sign_secretkey( $other )
			)
		);

		self::assertFalse( LicenceSignature::verify( $body, time() ) );
	}

	public function testACapturedAnswerCannotBeReplayedLater(): void {
		$body = $this->sign(
			array(
				'status' => 'active',
				'tier'   => 'agency',
			)
		);

		// Valid signature, valid body, recorded a day ago — which is how a
		// revoked licence would go on working if freshness were not bound.
		self::assertFalse( LicenceSignature::verify( $body, time() + 86400 ) );
	}

	public function testVerificationIsSkippedWhenNoKeyIsConfigured(): void {
		Monkey\tearDown();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );

		// Defence in depth behind TLS: an install with no key configured
		// must keep working rather than fail every check closed.
		self::assertFalse( LicenceSignature::isConfigured() );
		self::assertTrue( LicenceSignature::verify( array( 'status' => 'active' ), time() ) );
	}

	/**
	 * Sign a body the way the licence server does.
	 *
	 * @param array<string, mixed> $payload Body.
	 * @return array<string, mixed>
	 */
	private function sign( array $payload ): array {
		$payload['signed_at'] = time();

		$canonical = $payload;
		ksort( $canonical );

		$payload['signature'] = base64_encode(
			sodium_crypto_sign_detached(
				(string) json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				$this->secret
			)
		);

		return $payload;
	}
}
