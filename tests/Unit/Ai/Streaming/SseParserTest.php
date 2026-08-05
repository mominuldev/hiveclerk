<?php
/**
 * SSE parser tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Ai\Streaming;

use Hiveclerk\Ai\Streaming\SseFrame;
use Hiveclerk\Ai\Streaming\SseParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The parser exists for one reason — chunk boundaries fall wherever the
 * network puts them — so that is what these tests are mostly about.
 *
 * @internal
 */
#[CoversClass( SseParser::class )]
#[CoversClass( SseFrame::class )]
final class SseParserTest extends TestCase {

	public function testParsesASingleFrame(): void {
		$parser = new SseParser();

		$frames = $parser->feed( "event: message_start\ndata: {\"a\":1}\n\n" );

		$this->assertCount( 1, $frames );
		$this->assertSame( 'message_start', $frames[0]->event );
		$this->assertSame( array( 'a' => 1 ), $frames[0]->json() );
	}

	public function testHoldsAPartialFrameUntilItCompletes(): void {
		$parser = new SseParser();

		$this->assertSame( array(), $parser->feed( 'data: {"te' ) );
		$this->assertSame( array(), $parser->feed( 'xt":"hel' ) );

		$frames = $parser->feed( "lo\"}\n\n" );

		$this->assertCount( 1, $frames );
		$this->assertSame( array( 'text' => 'hello' ), $frames[0]->json() );
	}

	public function testSplitsOnTheFrameBoundaryEvenMidBoundary(): void {
		$parser = new SseParser();

		// The blank-line terminator itself arrives across two chunks,
		// which is the case a naive "one chunk is one frame" parser drops.
		$this->assertSame( array(), $parser->feed( "data: one\n" ) );

		$frames = $parser->feed( "\ndata: two\n\n" );

		$this->assertCount( 2, $frames );
		$this->assertSame( 'one', $frames[0]->data );
		$this->assertSame( 'two', $frames[1]->data );
	}

	public function testHandlesCarriageReturnLineEndings(): void {
		$parser = new SseParser();

		$frames = $parser->feed( "event: ping\r\ndata: {}\r\n\r\n" );

		$this->assertCount( 1, $frames );
		// A stray \r left on the field name would stop "data" matching.
		$this->assertSame( 'ping', $frames[0]->event );
		$this->assertSame( '{}', $frames[0]->data );
	}

	public function testIgnoresCommentKeepAlives(): void {
		$parser = new SseParser();

		$frames = $parser->feed( ": keep-alive\n\ndata: real\n\n" );

		$this->assertCount( 1, $frames );
		$this->assertSame( 'real', $frames[0]->data );
	}

	public function testJoinsMultipleDataLinesWithNewlines(): void {
		$parser = new SseParser();

		$frames = $parser->feed( "data: first\ndata: second\n\n" );

		$this->assertCount( 1, $frames );
		$this->assertSame( "first\nsecond", $frames[0]->data );
	}

	public function testStripsOnlyOneLeadingSpace(): void {
		$parser = new SseParser();

		$frames = $parser->feed( "data:  indented\n\n" );

		$this->assertSame( ' indented', $frames[0]->data );
	}

	public function testFlushRecoversAnUnterminatedFinalFrame(): void {
		$parser = new SseParser();

		$this->assertSame( array(), $parser->feed( 'data: {"usage":{"output_tokens":7}}' ) );

		$frames = $parser->flush();

		$this->assertCount( 1, $frames );
		$this->assertSame( array( 'usage' => array( 'output_tokens' => 7 ) ), $frames[0]->json() );
	}

	public function testFlushReturnsNothingWhenTheBufferIsEmpty(): void {
		$parser = new SseParser();

		$parser->feed( "data: done\n\n" );

		$this->assertSame( array(), $parser->flush() );
	}

	public function testRecognisesTheDoneSentinelWhichIsNotJson(): void {
		$parser = new SseParser();

		$frames = $parser->feed( "data: [DONE]\n\n" );

		$this->assertTrue( $frames[0]->isDoneSentinel() );
		$this->assertSame( array(), $frames[0]->json() );
	}
}
