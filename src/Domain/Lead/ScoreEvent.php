<?php
/**
 * Score event entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

use DateTimeImmutable;

/**
 * One immutable award of points (D7 §5.2).
 *
 * Never updated in place. `scoreAfter` is stamped at write time so the
 * breakdown can be replayed exactly as it was — a running total
 * recomputed on read would change retrospectively the first time a
 * customer edits a rule's weight, and the screen this feeds exists
 * because a sales team does not believe a number it cannot audit.
 */
final class ScoreEvent {

	/**
	 * Construct.
	 *
	 * @param int|null               $id             Storage id.
	 * @param int                    $leadId         Lead this belongs to.
	 * @param int|null               $conversationId Conversation that triggered it.
	 * @param string|null            $ruleId         Rule key, null for AI and manual.
	 * @param string|null            $ruleLabel      What the breakdown line reads.
	 * @param ScoreSource            $source         Rule, model or person.
	 * @param int                    $points         Signed award.
	 * @param int                    $scoreAfter     Total immediately after this event.
	 * @param string|null            $rationale      Required for an AI adjustment.
	 * @param DateTimeImmutable|null $createdAt      When it happened, UTC.
	 */
	public function __construct(
		public ?int $id,
		public int $leadId,
		public ?int $conversationId = null,
		public ?string $ruleId = null,
		public ?string $ruleLabel = null,
		public ScoreSource $source = ScoreSource::Rule,
		public int $points = 0,
		public int $scoreAfter = 0,
		public ?string $rationale = null,
		public ?DateTimeImmutable $createdAt = null,
	) {
	}

	/**
	 * Whether this event is fit to store.
	 *
	 * An AI event with no rationale is the exact failure FR-LED-04
	 * forbids, so it is rejected at the domain rather than filtered out
	 * of the display — a stored event nobody can explain would still
	 * count toward the total.
	 *
	 * @return bool
	 */
	public function isValid(): bool {
		if ( 0 === $this->points ) {
			return false;
		}

		if ( ! $this->source->requiresRationale() ) {
			return true;
		}

		return null !== $this->rationale && '' !== trim( $this->rationale );
	}

	/**
	 * The breakdown line this event renders as.
	 *
	 * @return array<string, mixed>
	 */
	public function toBreakdownLine(): array {
		return array(
			'source'     => $this->source->value,
			'label'      => $this->ruleLabel ?? $this->source->label(),
			'points'     => $this->points,
			'rationale'  => $this->rationale,
			'created_at' => $this->createdAt?->format( 'Y-m-d H:i:s' ),
		);
	}
}
