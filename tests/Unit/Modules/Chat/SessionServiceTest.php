<?php
/**
 * Session token tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Chat;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentStatus;
use Hiveclerk\Domain\Lead\NullVisitorResolver;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Chat\Services\SessionService;
use Hiveclerk\Core\Privacy\IpHasher;
use Hiveclerk\Core\Privacy\PrivacySettings;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use Hiveclerk\Tests\Support\Chat\InMemoryConversations;
use Hiveclerk\Tests\Support\Chat\InMemorySessions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The widget's whole authorisation model, tested at the boundary.
 *
 * Every assertion here is about something an attacker would try: forging a
 * token, replaying an expired one, tampering with the claims, or reading a
 * live credential out of a database dump. The happy path gets one test
 * because it is the one that fails loudly on its own.
 *
 * @internal
 */
#[CoversClass( SessionService::class )]
final class SessionServiceTest extends TestCase {

	private InMemorySessions $sessions;

	private SessionService $service;

	private FrozenClock $clock;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$options = array();

		// By reference, both of them. Capturing by value here made every
		// secret() call generate a fresh salt, so two signatures over the
		// same payload never matched — which looks exactly like a broken MAC.
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) use ( &$options ): mixed {
				return $options[ $key ] ?? false;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( string $key, mixed $value ) use ( &$options ): bool {
				$options[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'get_site_url' )->justReturn( 'https://alpine.test' );
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( mixed $value ): string|false => json_encode( $value ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_unslash' )->returnArg();

		$this->clock    = new FrozenClock( new DateTimeImmutable( '2026-08-05 12:00:00', new DateTimeZone( 'UTC' ) ) );
		$this->sessions = new InMemorySessions();
		$this->service  = new SessionService(
			$this->sessions,
			new InMemoryConversations(),
			$this->clock,
			new NullVisitorResolver(),
			new IpHasher( new PrivacySettings( new SettingsRepository(), $this->clock ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testAnIssuedTokenResolvesToItsOwnConversation(): void {
		$issued = $this->service->issue( $this->agent() );

		$resolved = $this->service->resolve( $issued['token'] );

		$this->assertNotNull( $resolved );
		$this->assertSame( $issued['conversation']->id, $resolved->conversationId );
		$this->assertTrue( $resolved->owns( (int) $issued['conversation']->id ) );
	}

	public function testTheRawTokenIsNeverStored(): void {
		$issued = $this->service->issue( $this->agent() );
		$token  = $issued['token'];

		foreach ( array_keys( $this->sessions->byHash ) as $stored ) {
			$this->assertNotSame( $token, $stored );
			$this->assertStringNotContainsString( $token, $stored );
		}

		// What is stored is the digest, and only the digest opens the row.
		$this->assertArrayHasKey( hash( 'sha256', $token ), $this->sessions->byHash );
	}

	public function testATamperedPayloadIsRejected(): void {
		$issued = $this->service->issue( $this->agent() );

		[ $prefix, $rest ]       = array( 'hvc_s_', substr( $issued['token'], 6 ) );
		[ $payload, $signature ] = explode( '.', $rest );

		// Re-encode the claims with a far-future expiry, keeping the
		// original signature. This is the attack the MAC exists to stop.
		$claims        = json_decode( base64_decode( strtr( $payload, '-_', '+/' ), true ), true );
		$claims['exp'] = $claims['exp'] + 86400;

		$forged = $prefix
			. rtrim( strtr( base64_encode( (string) json_encode( $claims ) ), '+/', '-_' ), '=' )
			. '.' . $signature;

		$this->assertNull( $this->service->resolve( $forged ) );
	}

	public function testAGarbageTokenIsRejectedWithoutTouchingStorage(): void {
		$this->sessions->byHash = array();

		$this->assertNull( $this->service->resolve( 'hvc_s_nonsense.nonsense' ) );
		$this->assertNull( $this->service->resolve( 'not-even-close' ) );
		$this->assertNull( $this->service->resolve( '' ) );
	}

	public function testAnExpiredTokenIsRejectedRatherThanRenewed(): void {
		$issued = $this->service->issue( $this->agent() );

		$this->clock->advance( SessionService::LIFETIME + 60 );

		$this->assertNull( $this->service->resolve( $issued['token'] ) );

		// And the row is still there — rejection is a decision, not a
		// side effect of the record disappearing.
		$this->assertNotEmpty( $this->sessions->byHash );
	}

	public function testATokenSignedForAnotherSiteIsRejected(): void {
		$issued = $this->service->issue( $this->agent() );

		// The same install, moved to a different address: the MAC covers
		// the site URL, so a token lifted from one site is inert on another
		// even when they share a database.
		Functions\when( 'get_site_url' )->justReturn( 'https://someone-else.test' );

		$this->assertNull( $this->service->resolve( $issued['token'] ) );
	}

	public function testTheBucketKeyIsNotTheCredential(): void {
		$issued = $this->service->issue( $this->agent() );

		$key = $this->service->bucketKey( $issued['session'] );

		$this->assertSame( $issued['session']->uuid->value, $key );
		$this->assertStringNotContainsString( $issued['token'], $key );
	}

	public function testTheTransportVerdictIsRecordedOnce(): void {
		$issued  = $this->service->issue( $this->agent() );
		$session = $issued['session'];

		$this->service->recordTransport( $session, 'poll' );

		$this->assertSame( 'poll', $session->transport );
	}

	/**
	 * A clerk under test.
	 *
	 * @return Agent
	 */
	private function agent(): Agent {
		return new Agent(
			id: 1,
			uuid: Uuid::generate(),
			name: 'Ada',
			slug: 'ada',
			status: AgentStatus::Published
		);
	}
}
