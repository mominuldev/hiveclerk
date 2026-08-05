<?php
/**
 * Provider stream translation tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Ai\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Ai\ChatTurn;
use Hiveclerk\Ai\Completion;
use Hiveclerk\Ai\CompletionRequest;
use Hiveclerk\Ai\Credentials;
use Hiveclerk\Ai\Http\HttpResponse;
use Hiveclerk\Ai\LlmProviderInterface;
use Hiveclerk\Ai\PricingTable;
use Hiveclerk\Ai\Providers\AnthropicProvider;
use Hiveclerk\Ai\Providers\GoogleProvider;
use Hiveclerk\Ai\Providers\OpenAiProvider;
use Hiveclerk\Ai\StreamEvent;
use Hiveclerk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Drives each adapter's streaming path with recorded provider frames.
 *
 * The three providers here are the three genuinely different wire
 * protocols — named events, anonymous chunks with a sentinel, and whole
 * responses per frame. Azure and OpenRouter share OpenAI's, which is the
 * entire reason they share its parser.
 *
 * Every stream is deliberately split across chunk boundaries that fall
 * inside frames.
 *
 * @internal
 */
#[CoversClass( AnthropicProvider::class )]
#[CoversClass( OpenAiProvider::class )]
#[CoversClass( GoogleProvider::class )]
final class StreamTranslationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'apply_filters' )->alias(
			static fn ( string $tag, mixed $value ): mixed => $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testAnthropicAssemblesTextAndUsageAcrossFrames(): void {
		$http = new FakeHttpClient(
			chunks: array(
				"event: message_start\ndata: {\"message\":{\"model\":\"claude-sonnet-4-5\",\"usage\":{\"input_tokens\":412}}}\n\n",
				"event: content_block_delta\ndata: {\"delta\":{\"type\":\"text_delta\",\"text\":\"Hel\"}}\n\nevent: content_bl",
				"ock_delta\ndata: {\"delta\":{\"type\":\"text_delta\",\"text\":\"lo\"}}\n\n",
				"event: message_delta\ndata: {\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":9}}\n\n",
				"event: message_stop\ndata: {}\n\n",
			)
		);

		$completion = self::runStream( new AnthropicProvider( $http, new PricingTable() ) );

		$this->assertSame( 'Hello', $completion->text );
		// Input tokens arrive on the first frame, output on the last but
		// one. Both have to survive to the done event.
		$this->assertSame( 412, $completion->tokensIn );
		$this->assertSame( 9, $completion->tokensOut );
		$this->assertSame( 'end_turn', $completion->finishReason );
	}

	public function testOpenAiReadsUsageFromTheFinalChunkThatHasNoChoices(): void {
		$http = new FakeHttpClient(
			chunks: array(
				"data: {\"model\":\"gpt-5-mini\",\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\n",
				"data: {\"choices\":[{\"delta\":{\"content\":\" there\"},\"finish_reason\":\"stop\"}]}\n\n",
				"data: {\"choices\":[],\"usage\":{\"prompt_tokens\":88,\"completion_tokens\":4}}\n\n",
				"data: [DONE]\n\n",
			)
		);

		$completion = self::runStream( new OpenAiProvider( $http, new PricingTable() ) );

		$this->assertSame( 'Hi there', $completion->text );
		$this->assertSame( 88, $completion->tokensIn );
		$this->assertSame( 4, $completion->tokensOut );
	}

	public function testOpenAiAsksForStreamUsage(): void {
		$http = new FakeHttpClient( chunks: array( "data: [DONE]\n\n" ) );

		self::runStream( new OpenAiProvider( $http, new PricingTable() ) );

		// Without this the whole streamed conversation records zero tokens.
		$this->assertSame(
			array( 'include_usage' => true ),
			$http->lastBody()['stream_options'] ?? null
		);
	}

	public function testGoogleTreatsTheFinishReasonAsTheTerminator(): void {
		$http = new FakeHttpClient(
			chunks: array(
				"data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"One \"}]}}],\"usageMetadata\":{\"promptTokenCount\":10,\"candidatesTokenCount\":1}}\n\n",
				"data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"two\"}]},\"finishReason\":\"MAX_TOKENS\"}],\"usageMetadata\":{\"promptTokenCount\":10,\"candidatesTokenCount\":2}}\n\n",
			)
		);

		$completion = self::runStream( new GoogleProvider( $http, new PricingTable() ) );

		$this->assertSame( 'One two', $completion->text );
		// Gemini sends SCREAMING_CASE; lower-casing is what makes the
		// truncation metric work across all five providers.
		$this->assertSame( 'max_tokens', $completion->finishReason );
		$this->assertTrue( $completion->wasTruncated() );
		$this->assertSame( 2, $completion->tokensOut );
	}

	public function testGoogleAsksForSseRatherThanAJsonArray(): void {
		$http = new FakeHttpClient( chunks: array() );

		self::runStream( new GoogleProvider( $http, new PricingTable() ) );

		$this->assertStringContainsString( ':streamGenerateContent', $http->lastUrl() );
		$this->assertStringContainsString( 'alt=sse', $http->lastUrl() );
	}

	public function testAStreamThatEndsWithoutATerminatorStillReportsUsage(): void {
		// A provider that closes the connection cleanly after its last
		// delta. The caller must still get a done event, or the call is
		// never metered.
		$http = new FakeHttpClient(
			chunks: array( "data: {\"choices\":[{\"delta\":{\"content\":\"partial\"}}],\"usage\":{\"prompt_tokens\":5,\"completion_tokens\":1}}\n\n" )
		);

		$completion = self::runStream( new OpenAiProvider( $http, new PricingTable() ) );

		$this->assertSame( 'partial', $completion->text );
		$this->assertSame( 5, $completion->tokensIn );
	}

	public function testAnHttpErrorArrivesAsAnErrorEventNotAnException(): void {
		$http = new FakeHttpClient(
			chunks: array( '{"error":{"message":"Rate limit reached."}}' ),
			status: 429
		);

		$events = self::collect( new OpenAiProvider( $http, new PricingTable() ) );

		$this->assertCount( 1, $events );
		$this->assertSame( StreamEvent::ERROR, $events[0]->type );
		$this->assertStringContainsString( 'Rate limit reached.', $events[0]->message );
		$this->assertTrue( $events[0]->retryable );
	}

	public function testAnUnconfiguredProviderFailsBeforeAnyRequest(): void {
		$http   = new FakeHttpClient();
		$events = array();

		( new OpenAiProvider( $http, new PricingTable() ) )->stream(
			Credentials::none(),
			self::request(),
			static function ( StreamEvent $event ) use ( &$events ): bool {
				$events[] = $event;

				return true;
			}
		);

		$this->assertSame( StreamEvent::ERROR, $events[0]->type );
		$this->assertSame( array(), $http->calls );
	}

	public function testANonStreamedAnthropicReplyIgnoresNonTextBlocks(): void {
		$body = '{"content":[{"type":"tool_use","name":"lookup"},'
			. '{"type":"text","text":"Visible answer."}],'
			. '"model":"claude-sonnet-4-5","stop_reason":"end_turn",'
			. '"usage":{"input_tokens":3,"output_tokens":4}}';

		$http = new FakeHttpClient( new HttpResponse( 200, $body ) );

		$completion = ( new AnthropicProvider( $http, new PricingTable() ) )
			->complete( new Credentials( 'sk-ant-test' ), self::request() );

		// A tool-use block concatenated into the reply would print raw
		// JSON to the visitor.
		$this->assertSame( 'Visible answer.', $completion->text );
		$this->assertSame( 3, $completion->tokensIn );
	}

	/**
	 * Run a stream and return the final completion.
	 *
	 * @param LlmProviderInterface $provider Adapter under test.
	 * @return Completion
	 */
	private static function runStream( LlmProviderInterface $provider ): Completion {
		foreach ( self::collect( $provider ) as $event ) {
			if ( StreamEvent::DONE === $event->type && null !== $event->completion ) {
				return $event->completion;
			}
		}

		self::fail( 'The stream produced no done event.' );
	}

	/**
	 * Run a stream and collect every event.
	 *
	 * @param LlmProviderInterface $provider Adapter under test.
	 * @return array<int, StreamEvent>
	 */
	private static function collect( LlmProviderInterface $provider ): array {
		$events = array();

		$provider->stream(
			new Credentials( 'test-key', 'https://example.test' ),
			self::request(),
			static function ( StreamEvent $event ) use ( &$events ): bool {
				$events[] = $event;

				return true;
			}
		);

		return $events;
	}

	/**
	 * A minimal request.
	 *
	 * @return CompletionRequest
	 */
	private static function request(): CompletionRequest {
		return new CompletionRequest(
			'test-model',
			array( ChatTurn::user( 'Hello?' ) ),
			'You are a clerk.'
		);
	}
}
