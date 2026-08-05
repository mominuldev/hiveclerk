<?php
/**
 * Human handoff tests.
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
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationStatus;
use Hiveclerk\Domain\Conversation\MessageRole;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Chat\Services\ChatService;
use Hiveclerk\Modules\Chat\Services\GuardrailService;
use Hiveclerk\Modules\Chat\Services\HandoffService;
use Hiveclerk\Modules\Chat\Services\PromptBuilder;
use Hiveclerk\Modules\KnowledgeBase\Text\TokenEstimator;
use Hiveclerk\Tests\Support\Chat\FakeAiService;
use Hiveclerk\Tests\Support\Chat\FakeRetrieval;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use Hiveclerk\Tests\Support\Chat\InMemoryAgents;
use Hiveclerk\Tests\Support\Chat\InMemoryCitations;
use Hiveclerk\Tests\Support\Chat\InMemoryConversations;
use Hiveclerk\Tests\Support\Chat\InMemoryMessages;
use Hiveclerk\Tests\Support\Chat\RecordingSink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A visitor asks for a person, and what has to stop happening afterwards.
 *
 * The behaviour worth protecting with a test is the silence: once someone
 * has asked for a human, a clerk that keeps answering has told them the
 * product is not listening, and no wording recovers that.
 *
 * @internal
 */
#[CoversClass( HandoffService::class )]
final class HandoffTest extends TestCase {

	private InMemoryConversations $conversations;

	private InMemoryMessages $messages;

	private HandoffService $handoff;

	/**
	 * Emails wp_mail() was asked to send.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mail = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->mail = array();

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Alpine Outfitters' );
		Functions\when( 'get_option' )->justReturn( 'shop@example.test' );
		Functions\when( 'is_email' )->alias( static fn ( string $email ): bool => str_contains( $email, '@' ) );
		Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/admin.php' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.test/wp-admin/admin.php?page=hiveclerk' );
		Functions\when( 'wp_mail' )->alias(
			function ( $to, string $subject, string $body ): bool {
				$this->mail[] = array(
					'to'      => $to,
					'subject' => $subject,
					'body'    => $body,
				);

				return true;
			}
		);

		$this->conversations = new InMemoryConversations();
		$this->messages      = new InMemoryMessages();

		$this->handoff = new HandoffService(
			$this->conversations,
			$this->messages,
			new FrozenClock( new DateTimeImmutable( '2026-08-05 10:00:00', new DateTimeZone( 'UTC' ) ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testRequestingFlagsTheConversationAndTellsSomebody(): void {
		$conversation = $this->handoff->request(
			$this->conversation(),
			$this->agent(),
			'can I speak to a person'
		);

		$this->assertSame( ConversationStatus::HandoffRequested, $conversation->status );
		$this->assertNotNull( $conversation->handoffAt );
		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( 'can I speak to a person', $this->mail[0]['body'] );

		// The visitor is told what happens next, in the transcript, so it
		// survives a page navigation like any other message.
		$this->assertCount( 1, $this->messages->saved );
		$this->assertSame( MessageRole::Assistant, $this->messages->saved[0]->role );
		$this->assertContains( 'handoff_requested', $this->messages->saved[0]->guardrailFlags );
	}

	public function testAskingTwiceDoesNotEmailTwice(): void {
		$conversation = $this->conversation();

		$this->handoff->request( $conversation, $this->agent() );
		$this->handoff->request( $conversation, $this->agent() );

		// This route sends mail on an anonymous request. Repeating it must
		// cost the site owner nothing.
		$this->assertCount( 1, $this->mail );
		$this->assertCount( 1, $this->messages->saved );
	}

	public function testTakingOverClearsTheResolvedByAiFlag(): void {
		$conversation               = $this->conversation();
		$conversation->resolvedByAi = true;

		$this->handoff->takeover( $conversation, 12 );

		$this->assertSame( ConversationStatus::HandoffActive, $conversation->status );
		$this->assertSame( 12, $conversation->handoffUserId );

		// A conversation a person had to finish was not resolved by the
		// clerk, and leaving the flag set inflates the dashboard's headline.
		$this->assertFalse( $conversation->resolvedByAi );
	}

	public function testReplyingTakesOverEvenWithoutPressingTheButton(): void {
		$conversation = $this->conversation();

		$message = $this->handoff->reply( $conversation, 7, 'Bulk orders start at 40 units.' );

		$this->assertSame( ConversationStatus::HandoffActive, $conversation->status );
		$this->assertSame( MessageRole::HumanAgent, $message->role );
		$this->assertSame( 7, $message->wpUserId );
		$this->assertSame( 1, $conversation->messageCount );
	}

	public function testTheClerkStopsAnsweringOnceAHumanIsAskedFor(): void {
		$agents = new InMemoryAgents();
		$ai     = new FakeAiService();

		$ai->deltas = array( 'It ', 'ships ', 'Tuesday.' );

		$chat = new ChatService(
			$ai,
			new FakeRetrieval(),
			new PromptBuilder( new TokenEstimator() ),
			new GuardrailService(),
			$agents,
			$this->conversations,
			$this->messages,
			new InMemoryCitations()
		);

		$conversation         = $this->conversation();
		$conversation->status = ConversationStatus::HandoffRequested;

		$sink    = new RecordingSink();
		$outcome = $chat->reply( $this->agent(), $conversation, 'and when does it ship?', $sink );

		$this->assertSame( '', $outcome->text );
		$this->assertContains( 'awaiting_human', $outcome->flags );

		// The question is still stored — it is what the colleague reads —
		// and no provider was called for it.
		$this->assertCount( 1, $this->messages->saved );
		$this->assertSame( MessageRole::Visitor, $this->messages->saved[0]->role );
		$this->assertSame( array( 'done' ), $sink->events );
	}

	/**
	 * A conversation with an id.
	 *
	 * @return Conversation
	 */
	private function conversation(): Conversation {
		return $this->conversations->save(
			new Conversation(
				id: null,
				uuid: Uuid::generate(),
				agentId: 1,
				pageUrl: 'https://example.test/products/alpine-jacket',
			)
		);
	}

	/**
	 * A published clerk.
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
			modelConfig: array(
				'provider' => 'anthropic',
				'model'    => 'claude-sonnet-5',
			),
		);
	}
}
