<?php
/**
 * Enrolment entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

use DateTimeImmutable;

/**
 * One lead's progress through one sequence.
 *
 * `nextSendAt` being a stored column rather than a computed one is what
 * makes the engine cheap: finding due work is an indexed range scan over
 * `(status, next_send_at)` rather than a walk over every enrolment
 * summing step delays. A site with forty thousand enrolments pays for the
 * ones that are due and nothing else.
 */
final class Enrollment {

	/**
	 * Construct.
	 *
	 * @param int|null               $id          Storage id, null before first save.
	 * @param int                    $sequenceId  Sequence.
	 * @param int                    $leadId      Lead.
	 * @param EnrollmentStatus       $status      Where it is.
	 * @param int                    $currentStep Position of the next step to send.
	 * @param DateTimeImmutable|null $nextSendAt  When that step is due, UTC.
	 * @param string|null            $exitReason  Why it stopped, when it did.
	 * @param DateTimeImmutable|null $enrolledAt  When it started, UTC.
	 * @param DateTimeImmutable|null $completedAt When it ended, UTC.
	 */
	public function __construct(
		public ?int $id,
		public int $sequenceId,
		public int $leadId,
		public EnrollmentStatus $status = EnrollmentStatus::Active,
		public int $currentStep = 0,
		public ?DateTimeImmutable $nextSendAt = null,
		public ?string $exitReason = null,
		public ?DateTimeImmutable $enrolledAt = null,
		public ?DateTimeImmutable $completedAt = null,
	) {
	}

	/**
	 * Stop this enrolment for a stated reason.
	 *
	 * The reason is required rather than optional. "Why did this person
	 * stop receiving the sequence" is the single most asked question
	 * about an email feature, and an enrolment that ended without one
	 * cannot answer it.
	 *
	 * @param string            $reason Why.
	 * @param DateTimeImmutable $at     When, UTC.
	 * @return void
	 */
	public function exit( string $reason, DateTimeImmutable $at ): void {
		$this->status      = EnrollmentStatus::Exited;
		$this->exitReason  = $reason;
		$this->completedAt = $at;
		$this->nextSendAt  = null;
	}

	/**
	 * Mark every step sent.
	 *
	 * @param DateTimeImmutable $at When, UTC.
	 * @return void
	 */
	public function complete( DateTimeImmutable $at ): void {
		$this->status      = EnrollmentStatus::Completed;
		$this->completedAt = $at;
		$this->nextSendAt  = null;
	}

	/**
	 * Move to the next step and schedule it.
	 *
	 * @param DateTimeImmutable $dueAt When the next step is due, UTC.
	 * @return void
	 */
	public function advance( DateTimeImmutable $dueAt ): void {
		++$this->currentStep;
		$this->nextSendAt = $dueAt;
	}
}
