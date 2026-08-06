<?php
/**
 * Sequence exit tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Email;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Domain\Email\Enrollment;
use Hiveclerk\Domain\Email\EnrollmentStatus;
use Hiveclerk\Modules\Email\Services\EnrolmentService;
use Hiveclerk\Modules\Email\Services\SuppressionList;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use Hiveclerk\Tests\Support\Email\InMemoryEnrollments;
use Hiveclerk\Tests\Support\Email\InMemorySequences;
use Hiveclerk\Tests\Support\Email\InMemorySteps;
use Hiveclerk\Tests\Support\Email\InMemorySuppressions;
use Hiveclerk\Tests\Support\Leads\InMemoryActivities;
use Hiveclerk\Tests\Support\Leads\InMemoryLeads;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Talking to a clerk again stops a follow-up that has already been sent.
 *
 * `ExitCondition` claimed for four sprints that the engine enforced this
 * for every sequence. It did not: `exitAll()` had no callers, no exit
 * condition covered it, and no signal existed that could have driven one.
 * These tests exist so the claim and the code cannot drift apart again.
 *
 * The second test is the one that makes the feature safe rather than
 * merely present. A lead is normally captured *during* a conversation, so
 * the visitor's next message lands seconds after enrolment — exiting on
 * any engagement would close every sequence before it sent anything, and
 * the feature would silently never work.
 *
 * @internal
 */
#[CoversClass( EnrolmentService::class )]
final class EnrolmentExitTest extends TestCase {

	private InMemoryEnrollments $enrollments;

	private EnrolmentService $enrolment;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );

		$clock = new FrozenClock(
			new DateTimeImmutable( '2026-08-06 09:30:00', new DateTimeZone( 'UTC' ) )
		);

		$this->enrollments = new InMemoryEnrollments();
		$leads             = new InMemoryLeads();

		$this->enrolment = new EnrolmentService(
			new InMemorySequences(),
			new InMemorySteps(),
			$this->enrollments,
			new SuppressionList(
				new InMemorySuppressions(),
				$this->enrollments,
				$leads,
				new InMemoryActivities(),
				$clock
			),
			$clock
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The point of the control.
	 */
	public function testComingBackToAClerkStopsASequenceThatHasSent(): void {
		$this->seed( id: 1, leadId: 42, currentStep: 1 );

		$closed = $this->enrolment->exitOnEngagement( 42 );

		self::assertSame( 1, $closed );
		self::assertSame( EnrollmentStatus::Exited, $this->enrollments->rows[1]->status );
	}

	/**
	 * The guard that stops the control eating its own feature.
	 */
	public function testAnEnrolmentThatHasSentNothingIsLeftAlone(): void {
		$this->seed( id: 1, leadId: 42, currentStep: 0 );

		$closed = $this->enrolment->exitOnEngagement( 42 );

		self::assertSame( 0, $closed );
		self::assertSame( EnrollmentStatus::Active, $this->enrollments->rows[1]->status );
	}

	/**
	 * "Why did this person stop receiving the sequence" must have an answer.
	 */
	public function testTheExitIsRecordedWithAReasonAndATime(): void {
		$this->seed( id: 1, leadId: 42, currentStep: 2 );

		$this->enrolment->exitOnEngagement( 42 );

		$enrollment = $this->enrollments->rows[1];

		self::assertSame( EnrolmentService::REASON_ENGAGED, $enrollment->exitReason );
		self::assertNotNull( $enrollment->completedAt );
		self::assertNull( $enrollment->nextSendAt );
	}

	/**
	 * One visitor's conversation must not close another person's sequence.
	 */
	public function testOnlyTheEngagingLeadIsAffected(): void {
		$this->seed( id: 1, leadId: 42, currentStep: 1 );
		$this->seed( id: 2, leadId: 99, currentStep: 1 );

		$this->enrolment->exitOnEngagement( 42 );

		self::assertSame( EnrollmentStatus::Exited, $this->enrollments->rows[1]->status );
		self::assertSame( EnrollmentStatus::Active, $this->enrollments->rows[2]->status );
	}

	/**
	 * Every message in a conversation fires this, so it must settle.
	 *
	 * A second call finding nothing open is what keeps the chat path from
	 * writing a row per message for the rest of the conversation.
	 */
	public function testASecondMessageClosesNothingFurther(): void {
		$this->seed( id: 1, leadId: 42, currentStep: 1 );

		self::assertSame( 1, $this->enrolment->exitOnEngagement( 42 ) );
		self::assertSame( 0, $this->enrolment->exitOnEngagement( 42 ) );
	}

	/**
	 * Store an open enrolment.
	 *
	 * @param int $id          Storage id.
	 * @param int $leadId      Lead.
	 * @param int $currentStep Next step to send.
	 * @return void
	 */
	private function seed( int $id, int $leadId, int $currentStep ): void {
		$this->enrollments->rows[ $id ] = new Enrollment(
			id: $id,
			sequenceId: 1,
			leadId: $leadId,
			currentStep: $currentStep,
			nextSendAt: new DateTimeImmutable( '2026-08-07 09:00:00', new DateTimeZone( 'UTC' ) )
		);
	}
}
