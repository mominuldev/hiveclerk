<?php
/**
 * Polling buffer tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Chat;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Modules\Chat\Streaming\BufferSink;
use Hiveclerk\Modules\Chat\Streaming\StreamBuffer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The store the two halves of the polling transport meet in.
 *
 * The encoding tests are the ones that matter. Sprint 4 lost a whole
 * caching layer to `strip_invalid_text_for_column()` silently truncating a
 * payload that was not valid UTF-8, and a reply accumulated one provider
 * delta at a time is transiently invalid by construction — a delta can
 * split a multibyte character down the middle. Asserting the round trip
 * over exactly that case is how this one does not repeat.
 *
 * @internal
 */
#[CoversClass( StreamBuffer::class )]
#[CoversClass( BufferSink::class )]
final class StreamBufferTest extends TestCase {

	/**
	 * Fake object cache.
	 *
	 * @var array<string, mixed>
	 */
	private array $cache = array();

	private StreamBuffer $buffer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->cache = array();

		Functions\when( 'wp_json_encode' )->alias(
			static fn ( mixed $value ): string|false => json_encode( $value ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );

		Functions\when( 'wp_cache_set' )->alias(
			function ( string $key, mixed $value ): bool {
				$this->cache[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->alias(
			function ( string $key ): mixed {
				return $this->cache[ $key ] ?? false;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( string $key ): bool {
				unset( $this->cache[ $key ] );

				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$this->buffer = new StreamBuffer();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testAppendedTextIsReadableByAnotherRequest(): void {
		$key = StreamBuffer::key( 'session-uuid', 'reference-uuid' );

		$this->buffer->open( $key );
		$this->buffer->append( $key, 'We ship to ' );
		$this->buffer->complete( $key, array( 'message_id' => 'abc' ) );

		$state = $this->buffer->read( $key );

		$this->assertNotNull( $state );
		$this->assertSame( 'We ship to ', $state['text'] );
		$this->assertTrue( $state['complete'] );
		$this->assertSame( 'abc', $state['message_id'] );
	}

	public function testAMultibyteReplySurvivesTheRoundTrip(): void {
		$key  = StreamBuffer::key( 'session-uuid', 'reference-uuid' );
		$text = '注文の追跡番号は — 3–5 日で届きます。Émigré café 🐝';

		$this->buffer->open( $key );

		// One byte at a time, which is the worst case a provider can
		// produce: most of these appends leave the accumulated string
		// mid-character and therefore not valid UTF-8.
		foreach ( str_split( $text ) as $byte ) {
			$this->buffer->append( $key, $byte );
		}

		$this->buffer->complete( $key, array( 'message_id' => 'abc' ) );

		$state = $this->buffer->read( $key );

		$this->assertNotNull( $state );
		$this->assertSame( $text, $state['text'] );
	}

	public function testTheStoredPayloadIsAscii(): void {
		$key = StreamBuffer::key( 'session-uuid', 'reference-uuid' );

		$this->buffer->open( $key );
		$this->buffer->replace( $key, 'Émigré 🐝' );

		$stored = $this->cache[ $key ] ?? '';

		// Base64 by design: without a persistent object cache this lands in
		// an option row, and an option row is a utf8mb4 column that quietly
		// rewrites anything it considers malformed.
		$this->assertIsString( $stored );
		$this->assertSame( 1, preg_match( '/^[A-Za-z0-9+\/=]+$/', $stored ) );
	}

	public function testWritesAreThrottledButTerminalEventsAreNot(): void {
		$key = StreamBuffer::key( 'session-uuid', 'reference-uuid' );

		$this->buffer->open( $key );

		for ( $i = 0; $i < 50; $i++ ) {
			$this->buffer->append( $key, 'token ' );
		}

		// The throttle means the cache does not yet hold every token, but
		// completing forces a write, so nothing is ever lost — only delayed.
		$this->buffer->complete( $key, array( 'message_id' => 'abc' ) );

		$state = $this->buffer->read( $key );

		$this->assertNotNull( $state );
		$this->assertSame( str_repeat( 'token ', 50 ), $state['text'] );
	}

	/**
	 * A flush costs a memory write with Redis and an option row without,
	 * so the throttle is a function of which one is in play.
	 *
	 * Each flush rewrites the whole answer so far, not the new part, so a
	 * ten-second reply at 150 ms was sixty-odd increasingly long writes
	 * into `wp_options` for a single visitor.
	 */
	public function testWritesAreThrottledHarderWhenEveryFlushIsADatabaseRow(): void {
		$writes = 0;

		Functions\when( 'set_transient' )->alias(
			function () use ( &$writes ): bool {
				++$writes;

				return true;
			}
		);
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );

		$buffer = new StreamBuffer();
		$key    = StreamBuffer::key( 'session-uuid', 'reference-uuid' );

		$buffer->open( $key );

		// Spread over a second of wall clock, which is what a real
		// completion does; without sleeping, the throttle would collapse
		// every append into one write and prove nothing.
		for ( $i = 0; $i < 10; $i++ ) {
			$buffer->append( $key, 'token ' );
			usleep( 100_000 );
		}

		$buffer->complete( $key, array( 'message_id' => 'abc' ) );

		// A second of appends at 450 ms is two or three flushes plus the
		// forced one at the end; at 150 ms it would be seven or more.
		self::assertLessThanOrEqual( 4, $writes );
		self::assertSame( str_repeat( 'token ', 10 ), $buffer->read( $key )['text'] );
	}

	public function testTheKeyIsScopedToTheSession(): void {
		$mine   = StreamBuffer::key( 'session-a', 'shared-reference' );
		$theirs = StreamBuffer::key( 'session-b', 'shared-reference' );

		// The same client-supplied reference under two sessions must not
		// name the same buffer. This is what makes a caller-chosen
		// identifier safe: it cannot address anything outside its own
		// session.
		$this->assertNotSame( $mine, $theirs );
	}

	public function testAFailureIsRecordedAsTerminal(): void {
		$key  = StreamBuffer::key( 'session-uuid', 'reference-uuid' );
		$sink = new BufferSink( $this->buffer, $key );

		$sink->start( 'message-uuid', 'conversation-uuid' );
		$sink->delta( 'partial' );
		$sink->error( 'hvc_provider_error', 'Cannot reach the provider.' );

		$state = $this->buffer->read( $key );

		$this->assertNotNull( $state );
		$this->assertTrue( $state['complete'] );
		$this->assertSame( 'hvc_provider_error', $state['error']['code'] );
	}

	public function testForgettingRemovesTheBuffer(): void {
		$key = StreamBuffer::key( 'session-uuid', 'reference-uuid' );

		$this->buffer->open( $key );
		$this->buffer->forget( $key );

		$this->assertNull( $this->buffer->read( $key ) );
	}
}
