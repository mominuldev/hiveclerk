<?php
/**
 * Sequence entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

use DateTimeImmutable;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * A follow-up sequence (FR-EML-01).
 *
 * `fromEmail` is nullable and usually null, which is deliberate. A
 * sequence that sets its own sender is a sequence that can quietly break
 * a site's SPF alignment and land every message in spam. Left unset, the
 * sender is whatever the site already sends everything else as — an
 * address whose deliverability is already established.
 */
final class EmailSequence {

	/**
	 * Construct.
	 *
	 * @param int|null               $id             Storage id, null before first save.
	 * @param Uuid                   $uuid           Public identifier.
	 * @param string                 $name           What the operator called it.
	 * @param SequenceStatus         $status         Draft, active, paused or archived.
	 * @param TriggerType            $trigger        What enrols a lead.
	 * @param array<string, mixed>   $triggerConfig  Threshold, stage, delay.
	 * @param array<int, array<string, mixed>> $exitConditions What takes a lead back out.
	 * @param string|null            $fromName       Sender name, or the site's default.
	 * @param string|null            $fromEmail      Sender address, or the site's default.
	 * @param string|null            $replyTo        Reply-to, where it differs.
	 * @param int                    $enrolledCount  Running total, for the list screen.
	 * @param DateTimeImmutable|null $createdAt      Row creation, UTC.
	 * @param DateTimeImmutable|null $updatedAt      Last write, UTC.
	 * @param DateTimeImmutable|null $deletedAt      Soft deletion, UTC.
	 */
	public function __construct(
		public ?int $id,
		public Uuid $uuid,
		public string $name,
		public SequenceStatus $status = SequenceStatus::Draft,
		public TriggerType $trigger = TriggerType::Manual,
		public array $triggerConfig = array(),
		public array $exitConditions = array(),
		public ?string $fromName = null,
		public ?string $fromEmail = null,
		public ?string $replyTo = null,
		public int $enrolledCount = 0,
		public ?DateTimeImmutable $createdAt = null,
		public ?DateTimeImmutable $updatedAt = null,
		public ?DateTimeImmutable $deletedAt = null,
	) {
	}

	/**
	 * The score a lead must reach under the threshold trigger.
	 *
	 * @return int
	 */
	public function threshold(): int {
		$value = $this->triggerConfig['threshold'] ?? null;

		return is_numeric( $value ) ? (int) $value : 60;
	}

	/**
	 * The stage a lead must enter under the stage trigger.
	 *
	 * @return int|null
	 */
	public function triggerStageId(): ?int {
		$value = $this->triggerConfig['stage_id'] ?? null;

		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Minutes of silence that count as an abandoned conversation.
	 *
	 * Thirty by default. Short enough that the follow-up still refers to
	 * something the visitor remembers; long enough that somebody who went
	 * to make a cup of tea is not chased for it.
	 *
	 * @return int
	 */
	public function abandonAfterMinutes(): int {
		$value = $this->triggerConfig['abandon_after'] ?? null;

		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : 30;
	}

	/**
	 * Whether this sequence is accepting enrolments.
	 *
	 * @return bool
	 */
	public function isActive(): bool {
		return null === $this->deletedAt && $this->status->accepts();
	}
}
