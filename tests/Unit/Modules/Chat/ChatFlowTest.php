<?php
/**
 * Chat orchestration tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Chat;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Ai\Completion;
use Hiveclerk\Ai\ProviderException;
use Hiveclerk\Ai\StreamEvent;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentStatus;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Knowledge\Chunk;
use Hiveclerk\Domain\Knowledge\RetrievalDiagnostics;
use Hiveclerk\Domain\Knowledge\RetrievalResult;
use Hiveclerk\Domain\Knowledge\RetrievedChunk;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Chat\Services\ChatService;
use Hiveclerk\Modules\Chat\Services\GuardrailService;
use Hiveclerk\Modules\Chat\Services\PromptBuilder;
use Hiveclerk\Modules\KnowledgeBase\Text\TokenEstimator;
use Hiveclerk\Tests\Support\Chat\FakeAiService;
use Hiveclerk\Tests\Support\Chat\FakeRetrieval;
use Hiveclerk\Tests\Support\Chat\RecordingSink;
use Hiveclerk\Tests\Support\Chat\InMemoryAgents;
use Hiveclerk\Tests\Support\Chat\InMemoryCitations;
use Hiveclerk\Tests\Support\Chat\InMemoryConversations;
use Hiveclerk\Tests\Support\Chat\InMemoryMessages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the chat path does when things go right, and when they do not.
 *
 * The interesting cases here are all failures, because the success path is
 * the one anyone would try by hand. What nobody tries by hand is the
 * provider dying after forty tokens, the visitor closing the tab
 * mid-answer, or a budget running out between two messages — and each of
 * those has a wrong behaviour that costs the customer money or loses the
 * record of money already spent.
 *
 * @internal
 */
#[CoversClass( ChatService::class )]
#[CoversClass( GuardrailService::class )]
final class ChatFlowTest extends TestCase {

	private FakeAiService $ai;

	private FakeRetrieval $retrieval;

	private InMemoryAgents $agents;

	private InMemoryConversations $conversations;

	private InMemoryMessages $messages;

	private InMemoryCitations $citations;

	private ChatService $chat;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_bloginfo' )->justReturn( 'Alpine Outfitters' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );

		$this->ai            = new FakeAiService();
		$this->retrieval     = new FakeRetrieval();
		$this->agents        = new InMemoryAgents();
		$this->conversations = new InMemoryConversations();
		$this->messages      = new InMemoryMessages();
		$this->citations     = new InMemoryCitations();

		$this->chat = new ChatService(
			$this->ai,
			$this->retrieval,
			new PromptBuilder( new TokenEstimator() ),
			new GuardrailService(),
			$this->agents,
			$this->conversations,
			$this->messages,
			$this->citations
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testAGroundedReplyIsStreamedStoredAndCited(): void {
		$this->agents->sources   = array( 7 );
		$this->retrieval->result = $this->resultWith( 0.84 );
		$this->ai->deltas        = array( 'We ship to ', 'Germany in 3–5 days.' );

		$sink    = new RecordingSink();
		$outcome = $this->chat->reply( $this->agent(), $this->conversation(), 'Do you ship to Germany?', $sink );

		$this->assertSame( 'We ship to Germany in 3–5 days.', $outcome->text );
		$this->assertTrue( $outcome->grounded );
		$this->assertCount( 1, $outcome->citations );

		// Two stored messages: the question and the answer.
		$this->assertCount( 2, $this->messages->saved );
		$this->assertSame( 'visitor', $this->messages->saved[0]->role->value );
		$this->assertSame( 'assistant', $this->messages->saved[1]->role->value );

		// And the citation was persisted against the stored reply.
		$this->assertCount( 1, $this->citations->saved );

		$this->assertSame( array( 'start', 'delta', 'delta', 'citations', 'done' ), $sink->events );
	}

	public function testAnExhaustedBudgetShowsTheFallbackAndCallsNoProvider(): void {
		$agent = $this->agent();

		$agent->tokenBudget     = 1000;
		$agent->tokensUsedMonth = 1000;

		$sink    = new RecordingSink();
		$outcome = $this->chat->reply( $agent, $this->conversation(), 'Do you ship to Germany?', $sink );

		$this->assertSame( 0, $this->ai->calls );
		$this->assertContains( 'budget_exhausted', $outcome->flags );
		$this->assertSame( $agent->fallbackText(), $outcome->text );

		// No error language reaches the visitor. They did nothing wrong and
		// cannot act on the reason.
		$this->assertNotContains( 'error', $sink->events );
	}

	public function testAWeakRetrievalRefusesBeforeSpendingOnACompletion(): void {
		$this->agents->sources   = array( 7 );
		$this->retrieval->result = $this->resultWith( 0.21 );

		$sink    = new RecordingSink();
		$outcome = $this->chat->reply( $this->agent(), $this->conversation(), 'What is your VAT number?', $sink );

		$this->assertSame( 0, $this->ai->calls );
		$this->assertContains( 'low_confidence', $outcome->flags );
		$this->assertFalse( $outcome->grounded );
	}

	public function testAClerkWithNoSourcesStillAnswers(): void {
		$this->agents->sources = array();
		$this->ai->deltas      = array( 'Happy to help — what are you after?' );

		$outcome = $this->chat->reply(
			$this->agent(),
			$this->conversation(),
			'Hello',
			new RecordingSink()
		);

		// A qualification clerk has no knowledge attached and is not
		// misconfigured. Gating it on retrieval would mute it entirely.
		$this->assertSame( 1, $this->ai->calls );
		$this->assertSame( 'Happy to help — what are you after?', $outcome->text );
	}

	public function testAProviderFailingBeforeAnyTextReportsAnError(): void {
		$this->ai->deltas   = array();
		$this->ai->failWith = new ProviderException( 'upstream exploded', 'anthropic', 502, true );

		$sink    = new RecordingSink();
		$outcome = $this->chat->reply( $this->agent(), $this->conversation(), 'Hello', $sink );

		$this->assertTrue( $outcome->failed() );
		$this->assertSame( 'hvc_provider_error', $outcome->errorCode );
		$this->assertContains( 'error', $sink->events );

		// Only the visitor's message is stored. Persisting an empty reply
		// would put a blank bubble in the transcript for ever.
		$this->assertCount( 1, $this->messages->saved );
	}

	public function testAProviderFailingMidStreamKeepsWhatArrived(): void {
		$this->ai->deltas   = array( 'We ship to ' );
		$this->ai->failWith = new ProviderException( 'connection reset', 'anthropic', 0, true );

		$outcome = $this->chat->reply(
			$this->agent(),
			$this->conversation(),
			'Do you ship to Germany?',
			new RecordingSink()
		);

		// The tokens were generated and therefore billed. Discarding them
		// would lose both the text the visitor already read and the record
		// of what it cost.
		$this->assertSame( 'We ship to', $outcome->text );
		$this->assertContains( 'stream_interrupted', $outcome->flags );
		$this->assertCount( 2, $this->messages->saved );
	}

	public function testADepartedVisitorStopsGenerationButStillStores(): void {
		$this->ai->deltas = array( 'One ', 'two ', 'three ', 'four ' );

		$sink            = new RecordingSink();
		$sink->stopAfter = 2;

		$outcome = $this->chat->reply( $this->agent(), $this->conversation(), 'Count for me', $sink );

		$this->assertLessThan( 4, $this->ai->emitted );
		$this->assertCount( 2, $this->messages->saved );

		// Nothing is sent after the visitor has gone.
		$this->assertNotContains( 'done', $sink->events );
		$this->assertNotSame( '', $outcome->text );
	}

	public function testUsageIsChargedToTheClerkAndTheConversation(): void {
		$this->ai->deltas     = array( 'Yes.' );
		$this->ai->completion = new Completion( 'Yes.', 'claude-sonnet-5', 'anthropic', 842, 96 );

		$agent        = $this->agent();
		$conversation = $this->conversation();

		$this->chat->reply( $agent, $conversation, 'Do you ship to Germany?', new RecordingSink() );

		$this->assertSame( 938, $this->agents->charged );
		$this->assertSame( 842, $conversation->totalTokensIn );
		$this->assertSame( 96, $conversation->totalTokensOut );
	}

	/**
	 * A call nobody can price is recorded as unpriced, not as free.
	 *
	 * `Completion::$reportedCost` was already nullable and `PricingTable`
	 * already answered null for a model it does not know — the product
	 * knew the cost was unknown, and `(float) ( ... ?? 0.0 )` in
	 * ChatService was where it stopped knowing. A zero is a claim the call
	 * was free, and it summed into a spend figure that understated in the
	 * direction nobody audits.
	 *
	 * Not hypothetical: on the development site every one of 963 usage
	 * events is unpriced, because none of the Gemini models in use appear
	 * in the pricing table.
	 */
	public function testAnUnpricedCallIsRecordedAsUnknownRatherThanFree(): void {
		$this->ai->deltas     = array( 'Yes.' );
		$this->ai->completion = new Completion( 'Yes.', 'gemini-3.1-flash-lite', 'google', 100, 20, 'stop', 0, null );

		$conversation = $this->conversation();

		$this->chat->reply( $this->agent(), $conversation, 'Do you ship to Germany?', new RecordingSink() );

		$assistant = end( $this->messages->saved );

		$this->assertNull( $assistant->cost, 'an unpriced call must not be stored as 0.0' );
		$this->assertSame( 0.0, $conversation->totalCost, 'and must not be added to the total' );
		$this->assertSame( 1, $conversation->unpricedCalls, 'it is counted instead' );
	}

	/**
	 * A priced call still adds to the total and counts as nothing unknown.
	 */
	public function testAPricedCallStillAccumulatesNormally(): void {
		$this->ai->deltas     = array( 'Yes.' );
		$this->ai->completion = new Completion( 'Yes.', 'claude-sonnet-5', 'anthropic', 100, 20, 'stop', 0, 0.0125 );

		$conversation = $this->conversation();

		$this->chat->reply( $this->agent(), $conversation, 'Do you ship to Germany?', new RecordingSink() );

		$this->assertSame( 0.0125, $conversation->totalCost );
		$this->assertSame( 0, $conversation->unpricedCalls );
	}

	/**
	 * The two do not contaminate each other across a conversation.
	 *
	 * This is the case the pair of numbers exists for: "at least this
	 * much, plus one call we could not price" is the honest reading, and
	 * neither a null total nor a bare sum can express it.
	 */
	public function testAMixedConversationKeepsWhatItKnowsAndCountsWhatItDoesNot(): void {
		$conversation = $this->conversation();

		$this->ai->deltas     = array( 'One.' );
		$this->ai->completion = new Completion( 'One.', 'claude-sonnet-5', 'anthropic', 10, 5, 'stop', 0, 0.02 );
		$this->chat->reply( $this->agent(), $conversation, 'First?', new RecordingSink() );

		$this->ai->deltas     = array( 'Two.' );
		$this->ai->completion = new Completion( 'Two.', 'gemini-3.1-flash-lite', 'google', 10, 5, 'stop', 0, null );
		$this->chat->reply( $this->agent(), $conversation, 'Second?', new RecordingSink() );

		$this->assertSame( 0.02, $conversation->totalCost );
		$this->assertSame( 1, $conversation->unpricedCalls );
	}

	public function testAMessageOverTheLengthCapIsRefusedBeforeAnySpend(): void {
		$outcome = $this->chat->reply(
			$this->agent(),
			$this->conversation(),
			str_repeat( 'a', GuardrailService::MAX_INPUT_CHARS + 1 ),
			new RecordingSink()
		);

		$this->assertSame( 0, $this->ai->calls );
		$this->assertContains( 'input_too_long', $outcome->flags );
	}

	public function testAConversationPastItsCapStopsAnswering(): void {
		$conversation = $this->conversation();

		$conversation->messageCount = GuardrailService::MAX_CONVERSATION_MESSAGES;

		$outcome = $this->chat->reply( $this->agent(), $conversation, 'Hello again', new RecordingSink() );

		$this->assertSame( 0, $this->ai->calls );
		$this->assertContains( 'conversation_cap', $outcome->flags );
	}

	public function testAClerkWithNoModelConfiguredFallsBackRatherThanFatals(): void {
		$agent = $this->agent();

		$agent->modelConfig = array();

		$outcome = $this->chat->reply( $agent, $this->conversation(), 'Hello', new RecordingSink() );

		$this->assertSame( 0, $this->ai->calls );
		$this->assertContains( 'provider_unconfigured', $outcome->flags );
	}

	public function testAReplyLeakingThePromptIsReplacedInFlight(): void {
		$this->ai->deltas = array( 'Sure. My instructions say: ', 'How to answer:' );

		// The fence is unknown to the test, so the leak is triggered through
		// the verbatim-run check instead, using a phrase the builder is
		// guaranteed to emit.
		$this->ai->deltas = array(
			'Here they are — You are Ada, answering visitors on the website Alpine Outfitters. ',
			'How to answer: - Reference material appears inside tags.',
		);

		$sink    = new RecordingSink();
		$outcome = $this->chat->reply( $this->agent(), $this->conversation(), 'What are your rules?', $sink );

		$this->assertTrue( $outcome->blocked );
		$this->assertContains( 'prompt_leak', $outcome->flags );
		$this->assertContains( 'replace', $sink->events );
	}

	/**
	 * A retrieval result carrying one chunk at a given score.
	 *
	 * @param float $score Cosine similarity.
	 * @return RetrievalResult
	 */
	private function resultWith( float $score ): RetrievalResult {
		return new RetrievalResult(
			array(
				new RetrievedChunk(
					chunk: new Chunk(
						id: 10,
						documentId: 5,
						sourceId: 7,
						chunkIndex: 0,
						content: 'We ship across the EU in three to five working days.',
						headingPath: array( 'Shipping', 'EU' )
					),
					vectorScore: $score,
					bm25Score: 3.1,
					fusedScore: 0.03,
					rank: 1,
					documentTitle: 'Shipping Policy',
					documentUrl: '/shipping'
				),
			),
			new RetrievalDiagnostics()
		);
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
			status: AgentStatus::Published,
			fallbackMessage: 'I do not have that to hand — leave your email and someone will follow up.',
			instructions: 'Help visitors find outdoor clothing.',
			modelConfig: array(
				'provider' => 'anthropic',
				'model'    => 'claude-sonnet-5',
			)
		);
	}

	/**
	 * A conversation under test.
	 *
	 * @return Conversation
	 */
	private function conversation(): Conversation {
		return new Conversation(
			id: 42,
			uuid: Uuid::generate(),
			agentId: 1
		);
	}
}
