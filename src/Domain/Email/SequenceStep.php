<?php
/**
 * Sequence step entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

use DateTimeImmutable;

/**
 * One email in a sequence, and the wait before it.
 *
 * ## The approval gate is a property of the step, not of the screen
 *
 * `aiGenerated` with no `approvedAt` means this step does not send. Not
 * "shows a warning" — does not send, enforced in `isSendable()` and
 * checked by the engine on every tick. FR-EML-03 asks for a human
 * approval gate, and a gate that lives only in the UI is a gate that a
 * direct API call walks around. A model wrote a paragraph that will go
 * out under the customer's name to a real person; a person says yes
 * first.
 *
 * `delayMinutes` is measured from the previous step's send, not from
 * enrolment. That is what makes "wait two days, then two days again"
 * express what an operator means when they build it.
 */
final class SequenceStep {

	/**
	 * Construct.
	 *
	 * @param int|null               $id           Storage id, null before first save.
	 * @param int                    $sequenceId   Owning sequence.
	 * @param int                    $position     Zero-based order.
	 * @param int                    $delayMinutes Wait after the previous step.
	 * @param string                 $subject      Subject line, merge tags allowed.
	 * @param string                 $bodyHtml     HTML body, merge tags allowed.
	 * @param string|null            $bodyText     Plain-text alternative.
	 * @param bool                   $aiGenerated  Whether a model drafted it.
	 * @param int|null               $approvedBy   Who signed it off.
	 * @param DateTimeImmutable|null $approvedAt   When, UTC.
	 * @param array<string, mixed>   $conditions   Per-step send conditions.
	 * @param DateTimeImmutable|null $createdAt    Row creation, UTC.
	 * @param DateTimeImmutable|null $updatedAt    Last write, UTC.
	 */
	public function __construct(
		public ?int $id,
		public int $sequenceId,
		public int $position = 0,
		public int $delayMinutes = 0,
		public string $subject = '',
		public string $bodyHtml = '',
		public ?string $bodyText = null,
		public bool $aiGenerated = false,
		public ?int $approvedBy = null,
		public ?DateTimeImmutable $approvedAt = null,
		public array $conditions = array(),
		public ?DateTimeImmutable $createdAt = null,
		public ?DateTimeImmutable $updatedAt = null,
	) {
	}

	/**
	 * Whether this step may go out.
	 *
	 * @return bool
	 */
	public function isSendable(): bool {
		if ( '' === trim( $this->subject ) || '' === trim( $this->bodyHtml ) ) {
			return false;
		}

		return ! $this->aiGenerated || null !== $this->approvedAt;
	}

	/**
	 * Why this step cannot go out, for the builder to show.
	 *
	 * Returns null when it can. Phrased as what to do rather than what is
	 * wrong, because the operator reading it is the person who fixes it.
	 *
	 * @return string|null
	 */
	public function blocker(): ?string {
		if ( '' === trim( $this->subject ) ) {
			return 'Give this step a subject line.';
		}

		if ( '' === trim( $this->bodyHtml ) ) {
			return 'Write the body of this email.';
		}

		if ( $this->aiGenerated && null === $this->approvedAt ) {
			return 'Read the draft and approve it before this sequence sends it.';
		}

		return null;
	}

	/**
	 * Mark a draft as signed off.
	 *
	 * @param int               $userId Who approved it.
	 * @param DateTimeImmutable $at     When, UTC.
	 * @return void
	 */
	public function approve( int $userId, DateTimeImmutable $at ): void {
		$this->approvedBy = $userId;
		$this->approvedAt = $at;
	}

	/**
	 * Withdraw approval, because the copy changed.
	 *
	 * Called whenever an AI-drafted step is edited. Approval attaches to
	 * words, not to a row: a step approved yesterday and rewritten today
	 * has not been read by anybody.
	 *
	 * @return void
	 */
	public function revokeApproval(): void {
		$this->approvedBy = null;
		$this->approvedAt = null;
	}
}
