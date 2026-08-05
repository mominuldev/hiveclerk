<?php
/**
 * Lead capture tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Leads;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentStatus;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\Message;
use Hiveclerk\Domain\Conversation\MessageRole;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Infrastructure\Http\OutboundUrlGuard;
use Hiveclerk\Modules\Leads\Services\LeadCaptureService;
use Hiveclerk\Modules\Leads\Services\LeadNotifier;
use Hiveclerk\Modules\Leads\Services\LeadService;
use Hiveclerk\Modules\Leads\Services\ScoringPolicy;
use Hiveclerk\Modules\Leads\Services\ScoringService;
use Hiveclerk\Modules\Leads\Services\SignalCollector;
use Hiveclerk\Core\Privacy\IpHasher;
use Hiveclerk\Core\Privacy\PrivacySettings;
use Hiveclerk\Modules\Leads\Services\VisitorService;
use Hiveclerk\Modules\Leads\Support\AnswerMatcher;
use Hiveclerk\Modules\Leads\Support\ContactExtractor;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use Hiveclerk\Tests\Support\Chat\InMemoryConversations;
use Hiveclerk\Tests\Support\Chat\InMemoryMessages;
use Hiveclerk\Tests\Support\Leads\InMemoryActivities;
use Hiveclerk\Tests\Support\Leads\InMemoryLeads;
use Hiveclerk\Tests\Support\Leads\InMemoryScoreEvents;
use Hiveclerk\Tests\Support\Leads\InMemoryStages;
use Hiveclerk\Tests\Support\Leads\InMemoryVisitors;
use Hiveclerk\Tests\Support\Leads\NullQueue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A conversation becoming a person (FR-LED-01, FR-LED-02, FR-LED-08).
 *
 * @internal
 */
#[CoversClass( LeadCaptureService::class )]
final class LeadCaptureTest extends TestCase {

	private InMemoryLeads $leads;

	private InMemoryMessages $messages;

	private InMemoryConversations $conversations;

	private LeadCaptureService $capture;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/admin.php' );
		Functions\when( 'add_query_arg' )->returnArg( 2 );

		$this->leads         = new InMemoryLeads();
		$this->messages      = new InMemoryMessages();
		$this->conversations = new InMemoryConversations();

		$clock      = new FrozenClock( new DateTimeImmutable( '2026-08-05 12:00:00', new DateTimeZone( 'UTC' ) ) );
		$events     = new InMemoryScoreEvents();
		$activities = new InMemoryActivities();
		$visitors   = new InMemoryVisitors();
		$stages     = InMemoryStages::withDefaults();
		$policy     = new ScoringPolicy( new SettingsRepository() );

		$scoring = new ScoringService(
			$this->leads,
			$events,
			$activities,
			$policy,
			new SignalCollector( $this->messages, $this->conversations, $visitors ),
			new LeadNotifier( $policy, $activities, new OutboundUrlGuard() ),
			$clock
		);

		$this->capture = new LeadCaptureService(
			$this->leads,
			$stages,
			$this->messages,
			$this->conversations,
			$visitors,
			new VisitorService( $visitors, $activities, $clock, new IpHasher( new PrivacySettings( new SettingsRepository(), $clock ) ) ),
			$scoring,
			new LeadService(
				$this->leads,
				$stages,
				$activities,
				$events,
				$visitors,
				$this->conversations,
				$scoring,
				$clock
			),
			new ContactExtractor(),
			new AnswerMatcher(),
			new NullQueue(),
			$clock
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A clerk with capture turned on.
	 *
	 * @param array<int, array<string, mixed>> $questions Qualification questions.
	 * @return Agent
	 */
	private function agent( array $questions = array() ): Agent {
		return new Agent(
			id: 1,
			uuid: Uuid::generate(),
			name: 'Rafi',
			slug: 'rafi',
			status: AgentStatus::Published,
			leadConfig: array(
				'enabled'   => true,
				'ask_after' => 1,
				'questions' => $questions,
			),
		);
	}

	/**
	 * A stored conversation with a transcript.
	 *
	 * @param array<int, array{0: MessageRole, 1: string}> $turns Turns, oldest first.
	 * @return Conversation
	 */
	private function conversation( array $turns ): Conversation {
		$conversation = $this->conversations->save(
			new Conversation( id: null, uuid: Uuid::generate(), agentId: 1 )
		);

		foreach ( $turns as [ $role, $content ] ) {
			$this->messages->save(
				new Message(
					id: null,
					uuid: Uuid::generate(),
					conversationId: (int) $conversation->id,
					role: $role,
					content: $content,
				)
			);
		}

		return $conversation;
	}

	public function testNoLeadIsCreatedWithoutAWayToReachAnybody(): void {
		$conversation = $this->conversation(
			array(
				array( MessageRole::Visitor, 'Do you ship to Austria?' ),
				array( MessageRole::Assistant, 'Yes, in two to three days.' ),
			)
		);

		self::assertNull( $this->capture->onReply( $conversation, $this->agent() ) );
		self::assertSame( array(), $this->leads->saved );
	}

	public function testAnAddressInTheTranscriptCreatesALead(): void {
		$conversation = $this->conversation(
			array(
				array( MessageRole::Visitor, 'Do you ship to Austria?' ),
				array( MessageRole::Assistant, 'Yes. Where should I send the details?' ),
				array( MessageRole::Visitor, 'sarah@nordwind.de' ),
				array( MessageRole::Assistant, 'Thanks, sending now.' ),
			)
		);

		$lead = $this->capture->onReply( $conversation, $this->agent() );

		self::assertInstanceOf( Lead::class, $lead );
		self::assertSame( 'sarah@nordwind.de', $lead->email );
		self::assertSame( 'rafi', $lead->source );
		self::assertSame( $lead->id, $conversation->leadId );
	}

	public function testTheSamePersonComingBackIsTheSameLead(): void {
		$first = $this->conversation(
			array(
				array( MessageRole::Visitor, 'sarah@nordwind.de' ),
				array( MessageRole::Assistant, 'Thanks.' ),
			)
		);

		$lead = $this->capture->onReply( $first, $this->agent() );

		$second = $this->conversation(
			array(
				array( MessageRole::Visitor, 'Me again — Sarah@Nordwind.de' ),
				array( MessageRole::Assistant, 'Welcome back.' ),
			)
		);

		$again = $this->capture->onReply( $second, $this->agent() );

		// Deduplication (FR-LED-08): a second conversation joins the first
		// rather than starting a parallel record of the same person.
		self::assertSame( $lead?->id, $again?->id );
		self::assertCount( 1, $this->leads->saved );
	}

	public function testDetailsAlreadyKnownAreNotOverwritten(): void {
		$conversation = $this->conversation(
			array(
				array( MessageRole::Visitor, 'my name is Sarah Klein, sarah@nordwind.de' ),
				array( MessageRole::Assistant, 'Thanks.' ),
			)
		);

		$lead = $this->capture->onReply( $conversation, $this->agent() );

		self::assertSame( 'Sarah', $lead?->firstName );

		// An operator corrected the spelling by hand. Capture runs again on
		// the next reply and must not put the visitor's version back.
		if ( null !== $lead ) {
			$lead->firstName = 'Sarah-Jane';
		}

		$this->capture->onReply( $conversation, $this->agent() );

		self::assertSame( 'Sarah-Jane', $lead?->firstName );
	}

	public function testAnAnswerIsPairedWithTheQuestionTheClerkAsked(): void {
		$agent = $this->agent(
			array(
				array(
					'key'      => 'budget',
					'question' => 'What is your budget for this?',
				),
			)
		);

		$conversation = $this->conversation(
			array(
				array( MessageRole::Visitor, 'sarah@nordwind.de' ),
				array( MessageRole::Assistant, 'Thanks. Roughly what is your budget for this?' ),
				array( MessageRole::Visitor, 'Around €12,000' ),
				array( MessageRole::Assistant, 'Understood.' ),
			)
		);

		$lead = $this->capture->onReply( $conversation, $agent );

		self::assertSame( 'Around €12,000', $lead?->answer( 'budget' ) );
	}

	public function testADeflectionIsNotStoredAsAnAnswer(): void {
		$agent = $this->agent(
			array(
				array(
					'key'      => 'budget',
					'question' => 'What is your budget for this?',
				),
			)
		);

		$conversation = $this->conversation(
			array(
				array( MessageRole::Visitor, 'sarah@nordwind.de' ),
				array( MessageRole::Assistant, 'Thanks. Roughly what is your budget for this?' ),
				array( MessageRole::Visitor, 'Not now' ),
				array( MessageRole::Assistant, 'No problem.' ),
			)
		);

		$lead = $this->capture->onReply( $conversation, $agent );

		// "Not now" in a budget field would then score, and a salesperson
		// would read it as a stated budget.
		self::assertNull( $lead?->answer( 'budget' ) );
	}

	public function testAnAnswerToADifferentQuestionIsNotStored(): void {
		$agent = $this->agent(
			array(
				array(
					'key'      => 'budget',
					'question' => 'What is your budget for this?',
				),
			)
		);

		$conversation = $this->conversation(
			array(
				array( MessageRole::Visitor, 'sarah@nordwind.de' ),
				array( MessageRole::Assistant, 'Thanks. Which country are you ordering from?' ),
				array( MessageRole::Visitor, 'Germany' ),
				array( MessageRole::Assistant, 'Understood.' ),
			)
		);

		$lead = $this->capture->onReply( $conversation, $agent );

		self::assertNull( $lead?->answer( 'budget' ) );
	}

	public function testCaptureDoesNothingWhenTheClerkHasItTurnedOff(): void {
		$agent = new Agent(
			id: 1,
			uuid: Uuid::generate(),
			name: 'Rafi',
			slug: 'rafi',
			status: AgentStatus::Published,
		);

		$conversation = $this->conversation(
			array(
				array( MessageRole::Visitor, 'sarah@nordwind.de' ),
				array( MessageRole::Assistant, 'Thanks.' ),
			)
		);

		self::assertNull( $this->capture->onReply( $conversation, $agent ) );
		self::assertSame( array(), $this->leads->saved );
	}

	public function testAFormSubmissionOverwritesWhatWasInferred(): void {
		$conversation = $this->conversation(
			array(
				array( MessageRole::Visitor, "I'm Sarah, sarah@nordwind.de" ),
				array( MessageRole::Assistant, 'Thanks.' ),
			)
		);

		$lead = $this->capture->onReply( $conversation, $this->agent() );

		self::assertSame( 'Sarah', $lead?->firstName );

		$this->capture->captureFromForm(
			$conversation,
			$this->agent(),
			array(
				'email'      => 'sarah@nordwind.de',
				'first_name' => 'Sarah-Jane',
			)
		);

		// The visitor typed this into a box that said what it was for.
		// A form field beats an inference.
		self::assertSame( 'Sarah-Jane', $lead?->firstName );
	}
}
