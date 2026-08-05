<?php
/**
 * SSE encoder tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Api\Streaming;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Ai\Streaming\SseParser;
use Hiveclerk\Api\Streaming\SseEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The encoder writes the wire format the parser reads.
 *
 * Most of these tests are round trips through SseParser rather than
 * assertions about byte strings. Two classes written from the same
 * specification can both be wrong in the same way, but they cannot
 * disagree silently: if the encoder emits something the parser we
 * already ship cannot read, that is a defect in one of them regardless
 * of which the specification favours.
 *
 * @internal
 */
#[CoversClass( SseEncoder::class )]
final class SseEncoderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_json_encode' )->alias(
			static fn ( mixed $value ): string|false => json_encode( $value ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testAnEventRoundTripsThroughTheParser(): void {
		$wire = SseEncoder::event( 'token', array( 'text' => 'Hello' ) );

		$frames = ( new SseParser() )->feed( $wire );

		$this->assertCount( 1, $frames );
		$this->assertSame( 'token', $frames[0]->event );
		$this->assertSame( array( 'text' => 'Hello' ), $frames[0]->json() );
	}

	public function testAPayloadContainingNewlinesSurvivesTheRoundTrip(): void {
		// A model answering with a code block emits newlines inside the
		// token payload. Encoded naively they terminate the data field and
		// the frame is truncated at the first line break.
		$text = "line one\nline two\n\nline four";

		$frames = ( new SseParser() )->feed( SseEncoder::event( 'token', array( 'text' => $text ) ) );

		$this->assertCount( 1, $frames );
		$this->assertSame( array( 'text' => $text ), $frames[0]->json() );
	}

	public function testUnicodeSurvivesTheRoundTrip(): void {
		$text = 'Grüße — 你好 · 🐝';

		$frames = ( new SseParser() )->feed( SseEncoder::event( 'token', array( 'text' => $text ) ) );

		$this->assertSame( array( 'text' => $text ), $frames[0]->json() );
	}

	public function testASequenceOfFramesParsesAsASequence(): void {
		$wire = SseEncoder::comment( 'open' )
			. SseEncoder::event( 'token', array( 'i' => 1 ) )
			. SseEncoder::event( 'token', array( 'i' => 2 ) )
			. SseEncoder::event( 'done', array() );

		$frames = ( new SseParser() )->feed( $wire );

		$events = array_map( static fn ( $frame ): ?string => $frame->event, $frames );

		$this->assertSame( array( 'token', 'token', 'done' ), $events );
	}

	public function testFramesSplitAcrossChunksStillParse(): void {
		$wire = SseEncoder::event( 'token', array( 'text' => 'streamed' ) );

		$parser = new SseParser();
		$frames = array();

		// One byte at a time is the pathological case, and the cheapest
		// way to prove no chunk boundary is special.
		foreach ( str_split( $wire ) as $byte ) {
			$frames = array_merge( $frames, $parser->feed( $byte ) );
		}

		$this->assertCount( 1, $frames );
		$this->assertSame( array( 'text' => 'streamed' ), $frames[0]->json() );
	}

	public function testAnEventNameCannotBreakFraming(): void {
		// Names come from our own code, but a newline in one would split a
		// single frame into two and truncate the answer.
		$wire = SseEncoder::event( "to\nken", array( 'i' => 1 ) );

		$frames = ( new SseParser() )->feed( $wire );

		$this->assertCount( 1, $frames );
		$this->assertSame( 'token', $frames[0]->event );
	}

	public function testCommentsCarryNoPayload(): void {
		$frames = ( new SseParser() )->feed( SseEncoder::comment( 'keep-alive' ) );

		// A comment is bytes on the wire and nothing else. It keeps the
		// connection warm without the receiver having to know about it.
		$this->assertSame( array(), $frames );
	}

	public function testACommentCannotBreakFraming(): void {
		$wire = SseEncoder::comment( "padding\n\nevent: injected" )
			. SseEncoder::event( 'token', array( 'i' => 1 ) );

		$frames = ( new SseParser() )->feed( $wire );

		$this->assertCount( 1, $frames );
		$this->assertSame( 'token', $frames[0]->event );
	}

	public function testRetryIsEmittedAsItsOwnField(): void {
		$this->assertSame( "retry: 5000\n\n", SseEncoder::retry( 5000 ) );
	}

	public function testUnencodablePayloadsBecomeAnEmptyObjectRatherThanNothing(): void {
		Functions\when( 'wp_json_encode' )->justReturn( false );

		$frames = ( new SseParser() )->feed( SseEncoder::event( 'token', array( 'text' => 'x' ) ) );

		// Still a well-formed frame. A payload that cannot be encoded is a
		// bug worth fixing, but emitting nothing would desynchronise the
		// stream and lose every frame after it too.
		$this->assertCount( 1, $frames );
		$this->assertSame( array(), $frames[0]->json() );
	}
}
