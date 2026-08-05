<?php
/**
 * GDPR erasure tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Privacy;

use Brain\Monkey;
use DateTimeImmutable;
use DateTimeZone;
use Brain\Monkey\Functions;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Privacy\PersonalDataEraser;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Email\SuppressionReason;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\Visitor;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use Hiveclerk\Tests\Support\Chat\InMemoryAudit;
use Hiveclerk\Tests\Support\Chat\InMemoryConversations;
use Hiveclerk\Tests\Support\Email\InMemoryEmailLog;
use Hiveclerk\Tests\Support\Email\InMemorySuppressions;
use Hiveclerk\Tests\Support\Leads\InMemoryLeads;
use Hiveclerk\Tests\Support\Leads\InMemoryVisitors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What an erasure removes, and the one thing it keeps.
 *
 * @internal
 */
#[CoversClass( PersonalDataEraser::class )]
final class PersonalDataEraserTest extends TestCase {

	private InMemoryLeads $leads;

	private InMemoryConversations $conversations;

	private InMemoryVisitors $visitors;

	private InMemoryEmailLog $emailLog;

	private InMemorySuppressions $suppressions;

	private PersonalDataEraser $eraser;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );

		$this->leads         = new InMemoryLeads();
		$this->conversations = new InMemoryConversations();
		$this->visitors      = new InMemoryVisitors();
		$this->emailLog      = new InMemoryEmailLog();
		$this->suppressions  = new InMemorySuppressions();

		$this->eraser = new PersonalDataEraser(
			$this->leads,
			$this->conversations,
			$this->visitors,
			$this->emailLog,
			$this->suppressions,
			new AuditLogger( new InMemoryAudit(), new FrozenClock( new DateTimeImmutable( '2026-08-06 12:00:00', new DateTimeZone( 'UTC' ) ) ) )
		);
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The transcript goes, not just the link to it.
	 *
	 * The regression this pins down: `LeadRepository::delete()` sets
	 * `lead_id = NULL` on conversations, which is right for an operator
	 * deleting a record and wrong for a person asking to be forgotten. An
	 * eraser built on it would leave every word the visitor typed on the
	 * site, unreachable from the admin and therefore invisible to whoever
	 * signed off the request as complete.
	 *
	 * @return void
	 */
	public function testConversationsArePurgedRatherThanDetached(): void {
		$this->seedLead();

		$this->conversations->saved[7] = new Conversation(
			id: 7,
			uuid: Uuid::generate(),
			agentId: 1,
			leadId: 1
		);

		$this->eraser->erase( 'tomas@example.com' );

		$this->assertSame( array( 7 ), $this->conversations->purged );
		$this->assertArrayNotHasKey( 7, $this->conversations->saved );
	}

	/**
	 * Visitors go too, fingerprint and all.
	 *
	 * @return void
	 */
	public function testVisitorsAreDeletedNotDetached(): void {
		$this->seedLead();

		$this->visitors->saved[] = new Visitor(
			id: 3,
			uuid: Uuid::generate(),
			leadId: 1,
			fingerprint: 'a-stable-browser-fingerprint',
			ipHash: str_repeat( 'a', 64 )
		);

		$this->eraser->erase( 'tomas@example.com' );

		$this->assertSame( array(), $this->visitors->saved );
	}

	/**
	 * The do-not-email hash survives, and the site owner is told.
	 *
	 * Erasing it would mean the next import re-subscribes somebody who had
	 * already unsubscribed — the erasure causing the harm it exists to
	 * prevent.
	 *
	 * @return void
	 */
	public function testTheSuppressionListIsRetainedAndReported(): void {
		$this->seedLead();

		$hash = Lead::hashEmail( 'tomas@example.com' );
		$this->assertNotNull( $hash );
		$this->suppressions->suppress( $hash, SuppressionReason::Unsubscribed );

		$result = $this->eraser->erase( 'tomas@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $this->suppressions->isSuppressed( $hash ) );
		$this->assertNotSame( array(), $result['messages'] );
	}

	/**
	 * Nothing suppressed means nothing to explain.
	 *
	 * @return void
	 */
	public function testNothingIsRetainedWhenThePersonNeverUnsubscribed(): void {
		$this->seedLead();

		$result = $this->eraser->erase( 'tomas@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( array(), $result['messages'] );
	}

	/**
	 * The email log is cleared by address, not by lead id.
	 *
	 * A send logged against a lead that has since been merged away keeps
	 * a `lead_id` pointing at nothing, and following the id would leave
	 * the address sitting in the log after the erasure.
	 *
	 * @return void
	 */
	public function testTheEmailLogIsClearedByAddress(): void {
		$this->seedLead();

		$this->emailLog->append(
			new \Hiveclerk\Domain\Email\EmailLogEntry(
				id: null,
				leadId: 999,
				toEmail: 'tomas@example.com',
				subject: 'Following up'
			)
		);

		$this->eraser->erase( 'tomas@example.com' );

		$this->assertSame( array(), $this->emailLog->forEmail( 'tomas@example.com', 10 ) );
	}

	/**
	 * An address the site does not hold reports done and removes nothing.
	 *
	 * WordPress calls an eraser until it says `done`, so a false here is
	 * an admin screen that never finishes.
	 *
	 * @return void
	 */
	public function testAnUnknownAddressCompletesWithoutRemovingAnything(): void {
		$result = $this->eraser->erase( 'nobody@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * So does something that is not an address at all.
	 *
	 * @return void
	 */
	public function testAMalformedAddressCompletes(): void {
		$result = $this->eraser->erase( 'not-an-address' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * Store a lead that owns the address under test.
	 *
	 * @return void
	 */
	private function seedLead(): void {
		$hash = Lead::hashEmail( 'tomas@example.com' );

		$this->leads->saved[1] = new Lead(
			id: 1,
			uuid: Uuid::generate(),
			email: 'tomas@example.com',
			emailHash: $hash
		);
	}
}
