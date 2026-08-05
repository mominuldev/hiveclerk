<?php
/**
 * Unsubscribe token tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Email;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Modules\Email\Services\UnsubscribeTokens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The link at the bottom of every email (FR-EML-06).
 *
 * Two things must hold. A link this site issued has to keep working
 * forever, because an unsubscribe that stops working is a compliance
 * failure rather than a security improvement. And a link somebody
 * assembled themselves has to be refused, because otherwise anyone can
 * unsubscribe anyone by editing a URL.
 *
 * @internal
 */
#[CoversClass( UnsubscribeTokens::class )]
final class UnsubscribeTokensTest extends TestCase {

	private UnsubscribeTokens $tokens;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'rest_url' )->alias(
			static fn ( string $path ): string => 'https://example.test/wp-json/' . $path
		);

		$this->tokens = new UnsubscribeTokens();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_token_it_issued_verifies(): void {
		$token = $this->tokens->forEmail( 'sam@example.com' );

		$this->assertNotNull( $token );
		$this->assertSame(
			Lead::hashEmail( 'sam@example.com' ),
			$this->tokens->verify( $token )
		);
	}

	public function test_it_is_case_insensitive_about_the_address(): void {
		// The address in the email header and the address on the lead can
		// differ in casing, and the same person must not end up with two
		// different tokens.
		$this->assertSame(
			$this->tokens->forEmail( 'Sam@Example.com' ),
			$this->tokens->forEmail( 'sam@example.com' )
		);
	}

	public function test_a_forged_signature_is_refused(): void {
		$hash = Lead::hashEmail( 'someone@example.com' );

		$this->assertNotNull( $hash );

		$this->assertNull( $this->tokens->verify( $hash . '.' . str_repeat( 'a', 64 ) ) );
	}

	public function test_a_token_with_a_swapped_hash_is_refused(): void {
		// The attack the signature exists for: take a valid token, replace
		// the address hash with somebody else's, unsubscribe them.
		$token  = (string) $this->tokens->forEmail( 'sam@example.com' );
		$victim = (string) Lead::hashEmail( 'victim@example.com' );

		$signature = explode( '.', $token )[1];

		$this->assertNull( $this->tokens->verify( $victim . '.' . $signature ) );
	}

	public function test_nonsense_is_refused_rather_than_fataling(): void {
		$this->assertNull( $this->tokens->verify( '' ) );
		$this->assertNull( $this->tokens->verify( 'nope' ) );
		$this->assertNull( $this->tokens->verify( '../../etc/passwd.aaa' ) );
	}

	public function test_an_address_that_is_not_one_produces_no_token(): void {
		$this->assertNull( $this->tokens->forEmail( 'not an address' ) );
		$this->assertNull( $this->tokens->url( 'not an address' ) );
	}

	public function test_the_url_carries_the_token(): void {
		$url = $this->tokens->url( 'sam@example.com' );

		$this->assertNotNull( $url );
		$this->assertStringContainsString( 'public/unsubscribe?token=', $url );
	}
}
