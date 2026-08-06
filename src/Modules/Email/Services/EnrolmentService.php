<?php
/**
 * Enrolment and exit.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Email\Services;

use DateTimeImmutable;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Email\EmailSequence;
use Hiveclerk\Domain\Email\Enrollment;
use Hiveclerk\Domain\Email\EnrollmentRepositoryInterface;
use Hiveclerk\Domain\Email\ExitCondition;
use Hiveclerk\Domain\Email\SequenceRepositoryInterface;
use Hiveclerk\Domain\Email\SequenceStepRepositoryInterface;
use Hiveclerk\Domain\Email\TriggerType;
use Hiveclerk\Domain\Lead\Lead;

/**
 * Puts leads into sequences and takes them back out (FR-EML-02, 04).
 *
 * ## Nobody is enrolled twice in the same sequence
 *
 * Enforced by a unique index on `(sequence_id, lead_id)` and checked here
 * before the insert. A lead whose score crosses a threshold four times in
 * one conversation would otherwise receive the same three-email sequence
 * four times over, which is the failure mode that gets a domain
 * blacklisted rather than merely ignored.
 *
 * The consequence is that a lead who completed a sequence cannot be put
 * through it again — which is right for a follow-up sequence and would be
 * wrong for a newsletter. This product does not have newsletters.
 *
 * ## Enrolment never sends
 *
 * The first step is scheduled, never sent inline, even when its delay is
 * zero. A lead captured mid-conversation would otherwise receive a
 * follow-up email while they are still typing, which reads as
 * surveillance rather than service.
 *
 * ## Coming back to the clerk stops the follow-up
 *
 * A sequence that keeps sending after the person answered is the most
 * damaging thing an email feature can do: it is visible to the recipient,
 * obviously automated, and it undoes the conversation that just started.
 * `exitOnEngagement()` closes any enrolment that has already emailed a
 * lead when that lead talks to a clerk again.
 *
 * It is deliberately *not* every enrolment. A lead is usually captured in
 * the middle of a conversation, so the visitor's next message arrives
 * seconds after enrolment — cancelling there would close a sequence that
 * has sent nothing, before it had any chance to. An enrolment still on
 * step zero has nothing to stop, so it is left alone.
 */
final class EnrolmentService {

	/**
	 * Exit reason recorded when a lead comes back and talks to a clerk.
	 *
	 * Not an `ExitCondition` case: that enum is the set of conditions a
	 * customer switches on per sequence, and this one is unconditional.
	 */
	public const REASON_ENGAGED = 'engaged';

	/**
	 * Construct.
	 *
	 * @param SequenceRepositoryInterface     $sequences   Sequence storage.
	 * @param SequenceStepRepositoryInterface $steps       Step storage.
	 * @param EnrollmentRepositoryInterface   $enrollments Enrolment storage.
	 * @param SuppressionList                 $suppression Do-not-email list.
	 * @param ClockInterface                  $clock       Clock.
	 */
	public function __construct(
		private readonly SequenceRepositoryInterface $sequences,
		private readonly SequenceStepRepositoryInterface $steps,
		private readonly EnrollmentRepositoryInterface $enrollments,
		private readonly SuppressionList $suppression,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Enrol a lead in every sequence this event triggers.
	 *
	 * @param Lead        $lead    The lead.
	 * @param TriggerType $trigger What happened.
	 * @param int|null    $stageId Stage, for the stage trigger.
	 * @return int How many enrolments were created.
	 */
	public function onTrigger( Lead $lead, TriggerType $trigger, ?int $stageId = null ): int {
		$created = 0;

		foreach ( $this->sequences->activeFor( $trigger ) as $sequence ) {
			if ( ! $this->matches( $sequence, $lead, $stageId ) ) {
				continue;
			}

			if ( null !== $this->enrol( $sequence, $lead ) ) {
				++$created;
			}
		}

		return $created;
	}

	/**
	 * Enrol one lead in one sequence.
	 *
	 * @param EmailSequence $sequence Sequence.
	 * @param Lead          $lead     The lead.
	 * @return Enrollment|null Null when the lead cannot or should not be enrolled.
	 */
	public function enrol( EmailSequence $sequence, Lead $lead ): ?Enrollment {
		if ( null === $sequence->id || null === $lead->id || null === $lead->email ) {
			return null;
		}

		if ( ! $sequence->isActive() ) {
			return null;
		}

		if ( $this->suppression->blocks( $lead->email ) ) {
			return null;
		}

		if ( null !== $this->enrollments->findFor( $sequence->id, $lead->id ) ) {
			return null;
		}

		$first = $this->steps->atPosition( $sequence->id, 0 );

		// A sequence with no steps enrols nobody. The alternative is an
		// enrolment that the engine picks up every tick and can never
		// advance, which is a slow leak nothing ever reports.
		if ( null === $first ) {
			return null;
		}

		$enrollment = $this->enrollments->save(
			new Enrollment(
				id: null,
				sequenceId: $sequence->id,
				leadId: $lead->id,
				currentStep: 0,
				nextSendAt: $this->due( $first->delayMinutes ),
				enrolledAt: $this->clock->now(),
			)
		);

		$this->sequences->incrementEnrolled( $sequence->id );

		/**
		 * Fires after a lead is enrolled in a sequence.
		 *
		 * @param Enrollment    $enrollment The enrolment.
		 * @param EmailSequence $sequence   The sequence.
		 * @param Lead          $lead       The lead.
		 */
		do_action( 'hiveclerk/email/enrolled', $enrollment, $sequence, $lead );

		return $enrollment;
	}

	/**
	 * Take a lead out of everything, for a stated reason.
	 *
	 * @param Lead   $lead   The lead.
	 * @param string $reason Why.
	 * @return int How many enrolments were closed.
	 */
	public function exitAll( Lead $lead, string $reason ): int {
		if ( null === $lead->id ) {
			return 0;
		}

		$closed = 0;

		foreach ( $this->enrollments->openForLead( $lead->id ) as $enrollment ) {
			$enrollment->exit( $reason, $this->clock->now() );

			$this->enrollments->save( $enrollment );

			++$closed;
		}

		return $closed;
	}

	/**
	 * Stop sequences that have already emailed a lead who has come back.
	 *
	 * Takes an id rather than a `Lead` because this runs on the chat reply
	 * path, once per visitor message on a conversation that has a lead
	 * attached. `openForLead()` is an indexed lookup returning the handful
	 * of sequences one person can be in; loading the lead as well would
	 * add a second query to that path for nothing, since no field on it is
	 * read here.
	 *
	 * @param int $leadId Lead storage id.
	 * @return int How many enrolments were closed.
	 */
	public function exitOnEngagement( int $leadId ): int {
		$closed = 0;

		foreach ( $this->enrollments->openForLead( $leadId ) as $enrollment ) {
			/*
			 * `currentStep` is the position of the *next* step to send, so
			 * zero means nothing has gone out yet. Those are the enrolments
			 * created moments ago by the capture that this same
			 * conversation produced, and closing them would mean no lead
			 * captured in a conversation ever receives a follow-up.
			 */
			if ( $enrollment->currentStep < 1 ) {
				continue;
			}

			$enrollment->exit( self::REASON_ENGAGED, $this->clock->now() );

			$this->enrollments->save( $enrollment );

			++$closed;
		}

		return $closed;
	}

	/**
	 * Take a lead out of the sequences whose exit conditions they now meet.
	 *
	 * @param Lead $lead The lead.
	 * @return int How many enrolments were closed.
	 */
	public function applyExitConditions( Lead $lead ): int {
		if ( null === $lead->id ) {
			return 0;
		}

		$closed = 0;

		foreach ( $this->enrollments->openForLead( $lead->id ) as $enrollment ) {
			$sequence = $this->sequences->find( $enrollment->sequenceId );

			if ( null === $sequence ) {
				continue;
			}

			$reason = $this->exitReason( $sequence, $lead );

			if ( null === $reason ) {
				continue;
			}

			$enrollment->exit( $reason, $this->clock->now() );

			$this->enrollments->save( $enrollment );

			++$closed;
		}

		return $closed;
	}

	/**
	 * Whether a lead now satisfies any of a sequence's exit conditions.
	 *
	 * @param EmailSequence $sequence Sequence.
	 * @param Lead          $lead     The lead.
	 * @return string|null The condition that fired, or null.
	 */
	public function exitReason( EmailSequence $sequence, Lead $lead ): ?string {
		foreach ( $sequence->exitConditions as $condition ) {
			$type = ExitCondition::fromStorage(
				isset( $condition['type'] ) && is_string( $condition['type'] ) ? $condition['type'] : null
			);

			if ( null === $type ) {
				continue;
			}

			$value = $condition['value'] ?? null;

			$met = match ( $type ) {
				ExitCondition::StageReached => is_numeric( $value ) && $lead->stageId === (int) $value,
				ExitCondition::StatusIs     => is_string( $value ) && $lead->status->value === $value,
				ExitCondition::ScoreAbove   => is_numeric( $value ) && $lead->score >= (int) $value,
				ExitCondition::Unsubscribed => null !== $lead->email && $this->suppression->blocks( $lead->email ),
			};

			if ( $met ) {
				return $type->value;
			}
		}

		return null;
	}

	/**
	 * Whether a sequence's trigger conditions are met by this event.
	 *
	 * @param EmailSequence $sequence Sequence.
	 * @param Lead          $lead     The lead.
	 * @param int|null      $stageId  Stage, for the stage trigger.
	 * @return bool
	 */
	private function matches( EmailSequence $sequence, Lead $lead, ?int $stageId ): bool {
		return match ( $sequence->trigger ) {
			TriggerType::ScoreThreshold => $lead->score >= $sequence->threshold(),
			TriggerType::StageChanged   => null === $sequence->triggerStageId()
				|| $sequence->triggerStageId() === $stageId,
			default                     => true,
		};
	}

	/**
	 * When a step with this delay is due.
	 *
	 * @param int $minutes Delay.
	 * @return DateTimeImmutable
	 */
	private function due( int $minutes ): DateTimeImmutable {
		return $this->clock->now()->modify( '+' . max( 0, $minutes ) . ' minutes' );
	}
}
