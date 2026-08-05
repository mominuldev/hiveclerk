<?php
/**
 * Sequence engine tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Email;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Domain\Email\EmailSequence;
use Hiveclerk\Domain\Email\Enrollment;
use Hiveclerk\Domain\Email\EnrollmentStatus;
use Hiveclerk\Domain\Email\SendStatus;
use Hiveclerk\Domain\Email\SequenceStatus;
use Hiveclerk\Domain\Email\SequenceStep;
use Hiveclerk\Domain\Email\SuppressionReason;
use Hiveclerk\Domain\Email\TriggerType;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Email\Services\EmailRenderer;
use Hiveclerk\Modules\Email\Services\EmailSender;
use Hiveclerk\Modules\Email\Services\EnrolmentService;
use Hiveclerk\Modules\Email\Services\MergeTags;
use Hiveclerk\Modules\Email\Services\SequenceEngine;
use Hiveclerk\Modules\Email\Services\SuppressionList;
use Hiveclerk\Modules\Email\Services\UnsubscribeTokens;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use Hiveclerk\Tests\Support\Email\InMemoryEmailLog;
use Hiveclerk\Tests\Support\Email\InMemoryEnrollments;
use Hiveclerk\Tests\Support\Email\InMemorySequences;
use Hiveclerk\Tests\Support\Email\InMemorySteps;
use Hiveclerk\Tests\Support\Email\InMemorySuppressions;
use Hiveclerk\Tests\Support\Leads\InMemoryActivities;
use Hiveclerk\Tests\Support\Leads\InMemoryLeads;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the tick sends, and what it refuses to send.
 *
 * Every test here is a version of one question: what stands between an
 * enrolment and an email arriving in somebody's inbox. Six things do, and
 * each of them is a real failure the feature would otherwise produce —
 * a duplicate, a message to somebody who unsubscribed, a paragraph no
 * human ever read, or a follow-up to a person who already replied.
 *
 * @internal
 */
#[CoversClass( SequenceEngine::class )]
final class SequenceEngineTest extends TestCase {

	private InMemorySequences $sequences;

	private InMemorySteps $steps;

	private InMemoryEnrollments $enrollments;

	private InMemoryEmailLog $log;

	private InMemorySuppressions $suppressions;

	private InMemoryLeads $leads;

	private SequenceEngine $engine;

	private FrozenClock $clock;

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
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'wp_kses' )->returnArg( 1 );
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn ( string $text ): string => strip_tags( $text )
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'rest_url' )->alias(
			static fn ( string $path ): string => 'https://example.test/wp-json/' . $path
		);
		Functions\when( 'get_option' )->justReturn( 'admin@example.test' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_mail' )->alias(
			function ( $to, string $subject ): bool {
				$this->mail[] = array(
					'to'      => $to,
					'subject' => $subject,
				);

				return true;
			}
		);

		$this->clock = new FrozenClock(
			new DateTimeImmutable( '2026-08-05 10:00:00', new DateTimeZone( 'UTC' ) )
		);

		$this->sequences    = new InMemorySequences();
		$this->steps        = new InMemorySteps();
		$this->enrollments  = new InMemoryEnrollments();
		$this->log          = new InMemoryEmailLog();
		$this->suppressions = new InMemorySuppressions();
		$this->leads        = new InMemoryLeads();

		$suppression = new SuppressionList(
			$this->suppressions,
			$this->enrollments,
			$this->leads,
			new InMemoryActivities(),
			$this->clock
		);

		$renderer = new EmailRenderer( new MergeTags(), new UnsubscribeTokens() );

		$sender = new EmailSender(
			$this->log,
			$suppression,
			new InMemoryActivities(),
			new SettingsRepository(),
			$this->clock
		);

		$this->engine = new SequenceEngine(
			$this->enrollments,
			$this->sequences,
			$this->steps,
			$this->log,
			$this->leads,
			$renderer,
			$sender,
			new EnrolmentService(
				$this->sequences,
				$this->steps,
				$this->enrollments,
				$suppression,
				$this->clock
			),
			$this->clock
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_it_sends_a_due_email(): void {
		$this->scenario();

		$result = $this->engine->tick();

		$this->assertSame( 1, $result['sent'] );
		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'sam@example.com', $this->mail[0]['to'] );
	}

	public function test_it_resolves_merge_tags_in_the_subject(): void {
		$this->scenario( array( 'subject' => 'Following up, {{first_name|there}}' ) );

		$this->engine->tick();

		$this->assertSame( 'Following up, Sam', $this->mail[0]['subject'] );
	}

	public function test_it_leaves_nothing_due_alone(): void {
		$this->scenario( array( 'due' => '+2 days' ) );

		$this->assertSame( 0, $this->engine->tick()['sent'] );
		$this->assertCount( 0, $this->mail );
	}

	public function test_it_never_sends_the_same_step_twice(): void {
		// WP-Cron runs the same job twice on any site with overlapping
		// requests. A duplicate email is the one failure in this module
		// that the recipient sees.
		$this->scenario();

		$enrollment = $this->enrollments->find( 1 );

		$this->assertNotNull( $enrollment );

		$this->engine->advance( $enrollment );

		// Wind it back as if the first run never recorded its progress.
		$enrollment->currentStep = 0;
		$enrollment->status      = EnrollmentStatus::Active;
		$enrollment->nextSendAt  = $this->clock->now();
		$this->enrollments->save( $enrollment );

		$this->engine->advance( $enrollment );

		$this->assertCount( 1, $this->mail );
	}

	public function test_an_unapproved_ai_draft_stops_the_enrolment(): void {
		// Held, not skipped. Skipping would send step three to somebody
		// who never received step two, and nothing would report it.
		$this->scenario(
			array(
				'ai'       => true,
				'approved' => false,
			)
		);

		$this->assertSame( 0, $this->engine->tick()['sent'] );
		$this->assertCount( 0, $this->mail );
		$this->assertSame(
			EnrollmentStatus::Active,
			$this->enrollments->find( 1 )?->status
		);
	}

	public function test_an_approved_ai_draft_sends(): void {
		$this->scenario(
			array(
				'ai'       => true,
				'approved' => true,
			)
		);

		$this->assertSame( 1, $this->engine->tick()['sent'] );
	}

	public function test_a_suppressed_address_is_logged_and_not_sent(): void {
		$this->scenario();

		$hash = Lead::hashEmail( 'sam@example.com' );

		$this->assertNotNull( $hash );

		$this->suppressions->suppress( $hash, SuppressionReason::Unsubscribed );

		$this->engine->tick();

		$this->assertCount( 0, $this->mail );
		$this->assertSame( SendStatus::Suppressed, $this->log->rows[0]->status );
	}

	public function test_a_paused_sequence_sends_nothing_and_keeps_its_place(): void {
		$this->scenario( array( 'status' => SequenceStatus::Paused ) );

		$this->assertSame( 0, $this->engine->tick()['sent'] );

		$enrollment = $this->enrollments->find( 1 );

		// Due time untouched, so resuming picks up where it stopped rather
		// than firing everything at once.
		$this->assertNotNull( $enrollment?->nextSendAt );
		$this->assertSame( 0, $enrollment->currentStep );
	}

	public function test_it_schedules_the_next_step_after_sending(): void {
		$this->scenario();

		$this->steps->save(
			new SequenceStep(
				id: null,
				sequenceId: 1,
				position: 1,
				delayMinutes: 2880,
				subject: 'Still there?',
				bodyHtml: '<p>Just checking.</p>',
			)
		);

		$this->engine->tick();

		$enrollment = $this->enrollments->find( 1 );

		$this->assertSame( 1, $enrollment?->currentStep );
		$this->assertSame(
			'2026-08-07 10:00:00',
			$enrollment?->nextSendAt?->format( 'Y-m-d H:i:s' )
		);
	}

	public function test_it_completes_when_the_last_step_has_gone(): void {
		$this->scenario();

		$this->engine->tick();

		$enrollment = $this->enrollments->find( 1 );

		$this->assertSame( EnrollmentStatus::Completed, $enrollment?->status );
		$this->assertNull( $enrollment?->nextSendAt );
	}

	public function test_an_exit_condition_stops_the_enrolment_before_sending(): void {
		$this->scenario();

		$sequence = $this->sequences->find( 1 );

		$this->assertNotNull( $sequence );

		$sequence->exitConditions = array(
			array(
				'type'  => 'score_above',
				'value' => '50',
			),
		);

		$lead = $this->leads->find( 1 );

		$this->assertNotNull( $lead );

		$lead->score = 80;
		$this->leads->save( $lead );

		$this->assertSame( 0, $this->engine->tick()['sent'] );
		$this->assertSame( EnrollmentStatus::Exited, $this->enrollments->find( 1 )?->status );
		$this->assertSame( 'score_above', $this->enrollments->find( 1 )?->exitReason );
	}

	public function test_a_deleted_lead_closes_the_enrolment(): void {
		$this->scenario();

		$this->leads->delete( 1 );

		$this->engine->tick();

		$this->assertSame( EnrollmentStatus::Exited, $this->enrollments->find( 1 )?->status );
		$this->assertSame( 'lead_missing', $this->enrollments->find( 1 )?->exitReason );
	}

	/**
	 * One active sequence, one step, one lead, one due enrolment.
	 *
	 * @param array<string, mixed> $options Overrides.
	 * @return void
	 */
	private function scenario( array $options = array() ): void {
		$this->sequences->save(
			new EmailSequence(
				id: null,
				uuid: Uuid::generate(),
				name: 'Follow-up',
				status: $options['status'] ?? SequenceStatus::Active,
				trigger: TriggerType::LeadCreated,
			)
		);

		$this->steps->save(
			new SequenceStep(
				id: null,
				sequenceId: 1,
				position: 0,
				delayMinutes: 0,
				subject: $options['subject'] ?? 'Following up',
				bodyHtml: '<p>Hello.</p>',
				aiGenerated: (bool) ( $options['ai'] ?? false ),
				approvedAt: ( $options['approved'] ?? false ) ? $this->clock->now() : null,
			)
		);

		$this->leads->save(
			new Lead(
				id: null,
				uuid: Uuid::generate(),
				email: 'sam@example.com',
				emailHash: Lead::hashEmail( 'sam@example.com' ),
				firstName: 'Sam',
			)
		);

		$this->enrollments->save(
			new Enrollment(
				id: null,
				sequenceId: 1,
				leadId: 1,
				nextSendAt: $this->clock->now()->modify( $options['due'] ?? '-1 minute' ),
				enrolledAt: $this->clock->now(),
			)
		);
	}
}
