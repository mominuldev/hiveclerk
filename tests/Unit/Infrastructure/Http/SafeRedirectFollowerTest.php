<?php
/**
 * SEC-06 redirect revalidation tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Infrastructure\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Infrastructure\Http\OutboundUrlGuard;
use Hiveclerk\Infrastructure\Http\SafeRedirectFollower;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * The crawler's redirect chain, hop by hop.
 *
 * The vulnerability these cover was real and measured: `wp_http_validate_url()`
 * accepts `http://169.254.169.254/` — the cloud instance metadata address —
 * so WordPress would follow a redirect there even though `OutboundUrlGuard`
 * rejects it, because the guard only ever saw the first URL.
 *
 * @internal
 */
#[CoversClass( SafeRedirectFollower::class )]
final class SafeRedirectFollowerTest extends TestCase {

	/**
	 * URLs the HTTP layer was actually asked for, in order.
	 *
	 * @var array<int, string>
	 */
	private array $requested = array();

	/**
	 * Canned responses, keyed by requested URL.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $responses = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component ) // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $r ) => $r['response']['code'] ?? 0
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static fn( $r, $h ) => $r['headers'][ $h ] ?? ''
		);
		Functions\when( 'wp_safe_remote_request' )->alias(
			function ( string $url ) {
				$this->requested[] = $url;

				return $this->responses[ $url ] ?? array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
				);
			}
		);
	}

	protected function tearDown(): void {
		$this->requested = array();
		$this->responses = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testARedirectToCloudMetadataIsRefused(): void {
		$this->responses['https://93.184.216.34/'] = array(
			'response' => array( 'code' => 302 ),
			'headers'  => array( 'location' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/' ),
		);

		$result = $this->follower()->request( 'https://93.184.216.34/', array() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'hiveclerk_blocked_url', $result->get_error_code() );

		// The decisive assertion: the metadata address was never requested.
		// Asserting only on the error would pass even if the fetch happened
		// and the failure came from somewhere else.
		self::assertSame( array( 'https://93.184.216.34/' ), $this->requested );
	}

	public function testARedirectToAPrivateAddressIsRefused(): void {
		$this->responses['https://93.184.216.34/'] = array(
			'response' => array( 'code' => 301 ),
			'headers'  => array( 'location' => 'http://10.0.0.5/admin' ),
		);

		self::assertInstanceOf( WP_Error::class, $this->follower()->request( 'https://93.184.216.34/', array() ) );
		self::assertNotContains( 'http://10.0.0.5/admin', $this->requested );
	}

	public function testAnOrdinaryRedirectChainIsStillFollowed(): void {
		$this->responses['https://93.184.216.34/old'] = array(
			'response' => array( 'code' => 301 ),
			'headers'  => array( 'location' => 'https://93.184.216.34/new' ),
		);

		$result = $this->follower()->request( 'https://93.184.216.34/old', array() );

		self::assertIsArray( $result );
		self::assertSame( 200, $result['response']['code'] );
		self::assertSame( array( 'https://93.184.216.34/old', 'https://93.184.216.34/new' ), $this->requested );
	}

	public function testANonHttpRedirectIsRefused(): void {
		$this->responses['https://93.184.216.34/'] = array(
			'response' => array( 'code' => 302 ),
			'headers'  => array( 'location' => 'file:///etc/passwd' ),
		);

		$result = $this->follower()->request( 'https://93.184.216.34/', array() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'hiveclerk_bad_redirect', $result->get_error_code() );
	}

	public function testARedirectLoopTerminates(): void {
		$this->responses['https://93.184.216.34/loop'] = array(
			'response' => array( 'code' => 302 ),
			'headers'  => array( 'location' => 'https://93.184.216.34/loop' ),
		);

		$result = $this->follower()->request( 'https://93.184.216.34/loop', array() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'hiveclerk_too_many_redirects', $result->get_error_code() );
		self::assertLessThanOrEqual( 6, count( $this->requested ) );
	}

	/**
	 * The real guard, not a stand-in.
	 *
	 * Every URL in these tests uses an IP literal, so `OutboundUrlGuard`
	 * short-circuits before any DNS lookup and the test is both offline and
	 * exercising the code that actually ships. A hand-written stub here would
	 * be testing the stub's idea of which ranges are private, which is the
	 * one thing in this area worth not getting wrong.
	 */
	private function follower(): SafeRedirectFollower {
		return new SafeRedirectFollower( new OutboundUrlGuard() );
	}
}
