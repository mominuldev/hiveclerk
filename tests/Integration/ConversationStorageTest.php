<?php
/**
 * Conversation storage integration tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Database\Repositories\CitationRepository;
use Hiveclerk\Database\Repositories\ConversationRepository;
use Hiveclerk\Database\Repositories\MessageRepository;
use Hiveclerk\Domain\Conversation\Citation;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationNote;
use Hiveclerk\Domain\Conversation\ConversationStatus;
use Hiveclerk\Domain\Conversation\Message;
use Hiveclerk\Domain\Conversation\MessageRole;
use Hiveclerk\Domain\Shared\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The SQL behind the conversations screen, against a real database.
 *
 * Unit tests cover the services with in-memory doubles, which is the right
 * place for the rules and the wrong place for these: the filters, the JSON
 * columns and the cascading purge are all statements, and a statement that
 * is wrong in a way a double would never notice is exactly the kind of bug
 * that reaches a customer.
 *
 * The purge is the one that matters most. It deletes real history on a
 * timer, and "did the children go too" is not a question an in-memory
 * repository can answer.
 *
 * @internal
 */
#[CoversClass( ConversationRepository::class )]
final class ConversationStorageTest extends WordPressTestCase {

	private ConversationRepository $conversations;

	private MessageRepository $messages;

	private CitationRepository $citations;

	/**
	 * Conversations this test created.
	 *
	 * @var array<int, int>
	 */
	private array $created = array();

	protected function setUp(): void {
		parent::setUp();

		$this->conversations = new ConversationRepository();
		$this->messages      = new MessageRepository();
		$this->citations     = new CitationRepository();
	}

	/**
	 * Remove anything left behind.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( array() !== $this->created ) {
			$this->conversations->purge( $this->created );
			$this->created = array();
		}

		parent::tearDown();
	}

	public function testTagsStarsAndNotesSurviveARoundTrip(): void {
		$conversation = $this->given();

		$conversation->tags    = array( 'bulk', 'germany' );
		$conversation->starred = true;
		$conversation->notes   = array(
			new ConversationNote( 'Quoted 40 units.', 1, 'Admin', '2026-08-05 10:00:00' ),
		);

		$this->conversations->save( $conversation );

		$reloaded = $this->conversations->find( (int) $conversation->id );

		$this->assertNotNull( $reloaded );
		$this->assertSame( array( 'bulk', 'germany' ), $reloaded->tags );
		$this->assertTrue( $reloaded->starred );
		$this->assertCount( 1, $reloaded->notes );
		$this->assertSame( 'Quoted 40 units.', $reloaded->notes[0]->text );
		$this->assertSame( 'Admin', $reloaded->notes[0]->authorName );
	}

	public function testHandoffFieldsRoundTripAsUtc(): void {
		$conversation = $this->given();

		$conversation->status        = ConversationStatus::HandoffActive;
		$conversation->handoffUserId = 7;
		$conversation->handoffAt     = new DateTimeImmutable( '2026-08-05 09:30:00', new DateTimeZone( 'UTC' ) );

		$this->conversations->save( $conversation );

		$reloaded = $this->conversations->find( (int) $conversation->id );

		$this->assertNotNull( $reloaded );
		$this->assertSame( ConversationStatus::HandoffActive, $reloaded->status );
		$this->assertSame( 7, $reloaded->handoffUserId );
		$this->assertSame( '2026-08-05 09:30:00', $reloaded->handoffAt?->format( 'Y-m-d H:i:s' ) );
	}

	public function testTheHandoffFilterFindsBothWaitingAndTakenOver(): void {
		$waiting  = $this->given();
		$taken    = $this->given();
		$ordinary = $this->given();

		$waiting->status = ConversationStatus::HandoffRequested;
		$taken->status   = ConversationStatus::HandoffActive;

		$this->conversations->save( $waiting );
		$this->conversations->save( $taken );

		$found = $this->conversations->count(
			array(
				'handoff'  => true,
				'agent_id' => $this->agentId(),
			)
		);

		$this->assertSame( 2, $found );
		$this->assertNotNull( $ordinary->id );
	}

	public function testTheStarFilterIsIndexBackedAndCorrect(): void {
		$starred = $this->given();
		$this->given();

		$starred->starred = true;
		$this->conversations->save( $starred );

		$this->assertSame(
			1,
			$this->conversations->count(
				array(
					'starred'  => true,
					'agent_id' => $this->agentId(),
				)
			)
		);
	}

	public function testPurgingTakesMessagesAndCitationsWithIt(): void {
		$conversation = $this->given();

		$message = $this->messages->save(
			new Message(
				id: null,
				uuid: Uuid::generate(),
				conversationId: (int) $conversation->id,
				role: MessageRole::Assistant,
				content: 'We ship to Germany in 3–5 days.',
			)
		);

		$this->citations->saveFor(
			(int) $message->id,
			array(
				new Citation(
					id: null,
					messageId: (int) $message->id,
					chunkId: 1,
					documentId: 1,
					score: 0.84,
					rank: 1,
					title: 'Shipping Policy',
				),
			)
		);

		$this->assertCount( 1, $this->messages->transcript( (int) $conversation->id ) );
		$this->assertCount( 1, $this->citations->forMessages( array( (int) $message->id ) ) );

		$deleted = $this->conversations->purge( array( (int) $conversation->id ) );

		$this->assertSame( 1, $deleted );
		$this->assertNull( $this->conversations->find( (int) $conversation->id ) );
		$this->assertCount( 0, $this->messages->transcript( (int) $conversation->id ) );
		$this->assertCount( 0, $this->citations->forMessages( array( (int) $message->id ) ) );

		// Already gone; nothing for tearDown to clean up.
		$this->created = array();
	}

	public function testTheRetentionSweepFindsOnlyWhatIsPastTheCutoff(): void {
		$old   = $this->given( '2020-01-01 09:00:00' );
		$fresh = $this->given( '2026-08-01 09:00:00' );

		$ids = $this->conversations->idsStartedBefore( '2021-01-01 00:00:00', 50 );

		$this->assertContains( (int) $old->id, $ids );
		$this->assertNotContains( (int) $fresh->id, $ids );
	}

	public function testPerClerkTotalsAreGroupedInOneQuery(): void {
		$first = $this->given();
		$this->given();

		$first->resolvedByAi = true;
		$first->totalCost    = 0.0031;

		$this->conversations->save( $first );

		$stats = $this->conversations->statsForAgents(
			array( $this->agentId() ),
			'2019-01-01 00:00:00'
		);

		$this->assertArrayHasKey( $this->agentId(), $stats );
		$this->assertSame( 2, $stats[ $this->agentId() ]['conversations'] );
		$this->assertSame( 1, $stats[ $this->agentId() ]['resolved'] );
		$this->assertEqualsWithDelta( 0.0031, $stats[ $this->agentId() ]['cost'], 0.000001 );
	}

	/**
	 * A stored conversation belonging to this test's synthetic clerk.
	 *
	 * @param string|null $startedAt UTC timestamp, or null for now.
	 * @return Conversation
	 */
	private function given( ?string $startedAt = null ): Conversation {
		$conversation = $this->conversations->save(
			new Conversation(
				id: null,
				uuid: Uuid::generate(),
				agentId: $this->agentId(),
				startedAt: null === $startedAt
					? null
					: new DateTimeImmutable( $startedAt, new DateTimeZone( 'UTC' ) ),
			)
		);

		$this->created[] = (int) $conversation->id;

		return $conversation;
	}

	/**
	 * A clerk id no real installation will collide with.
	 *
	 * The tests run against a developer's own database, which may already
	 * hold conversations. Scoping every count to an id nothing else uses is
	 * what keeps them from being wrong on a machine that has been used.
	 *
	 * @return int
	 */
	private function agentId(): int {
		return 999000042;
	}
}
