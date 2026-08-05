<?php
/**
 * Webhook signing tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Integrations;

use Hiveclerk\Modules\Integrations\Support\WebhookSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The signature a receiver verifies (D9 §4).
 *
 * Two properties matter and both are tested here: the timestamp is inside
 * the signed material, so a captured request cannot be replayed a month
 * later with its original signature intact; and the signature is computed
 * over the exact bytes sent, so re-encoding the body anywhere in the
 * pipeline breaks verification loudly rather than silently.
 *
 * @internal
 */
#[CoversClass( WebhookSigner::class )]
final class WebhookSignerTest extends TestCase {

	private WebhookSigner $signer;

	protected function setUp(): void {
		parent::setUp();

		$this->signer = new WebhookSigner();
	}

	public function test_a_receiver_can_reproduce_the_signature(): void {
		$body      = '{"event":"lead.qualified"}';
		$secret    = 'whsec_test';
		$timestamp = 1_700_000_000;

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		$this->assertSame( $expected, $this->signer->signature( $body, $secret, $timestamp ) );
	}

	public function test_the_timestamp_changes_the_signature(): void {
		$body   = '{"event":"lead.qualified"}';
		$secret = 'whsec_test';

		$this->assertNotSame(
			$this->signer->signature( $body, $secret, 1_700_000_000 ),
			$this->signer->signature( $body, $secret, 1_700_000_060 )
		);
	}

	public function test_a_single_changed_byte_changes_the_signature(): void {
		$secret = 'whsec_test';

		$this->assertNotSame(
			$this->signer->signature( '{"score":80}', $secret, 1_700_000_000 ),
			$this->signer->signature( '{"score":81}', $secret, 1_700_000_000 )
		);
	}

	public function test_headers_carry_the_scheme_prefix(): void {
		$headers = $this->signer->headers( '{}', 'whsec_test', 1_700_000_000, 'lead.captured' );

		$this->assertStringStartsWith( 'sha256=', $headers['X-HVC-Signature'] );
		$this->assertSame( '1700000000', $headers['X-HVC-Timestamp'] );
		$this->assertSame( 'lead.captured', $headers['X-HVC-Event'] );
	}

	public function test_an_unsigned_endpoint_gets_no_signature_header(): void {
		// An empty secret means the operator never set one. Sending
		// `sha256=` followed by an HMAC of the empty string would look
		// like a signature and verify against nothing.
		$headers = $this->signer->headers( '{}', '', 1_700_000_000, 'lead.captured' );

		$this->assertArrayNotHasKey( 'X-HVC-Signature', $headers );
		$this->assertArrayHasKey( 'X-HVC-Timestamp', $headers );
	}

	public function test_generated_secrets_are_unguessable_and_distinct(): void {
		$first  = $this->signer->generateSecret();
		$second = $this->signer->generateSecret();

		$this->assertNotSame( $first, $second );
		$this->assertStringStartsWith( 'whsec_', $first );
		// 24 random bytes as hex.
		$this->assertSame( 54, strlen( $first ) );
	}
}
