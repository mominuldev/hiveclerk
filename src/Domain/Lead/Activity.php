<?php
/**
 * Activity entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

use DateTimeImmutable;

/**
 * One line of the lead timeline (FR-LED-06).
 *
 * Carries both a lead id and a visitor id because the interesting half
 * of a timeline happens before anyone knows who the visitor is. Page
 * views are written against the visitor; stitching later fills in the
 * lead, and the same rows become "Viewed /pricing (2nd)" above "Lead
 * captured" instead of being thrown away with the anonymous session.
 */
final class Activity {

	/**
	 * Construct.
	 *
	 * @param int|null               $id          Storage id.
	 * @param ActivityType           $type        What happened.
	 * @param string                 $title       One-line summary shown on the timeline.
	 * @param int|null               $leadId      Lead, once known.
	 * @param int|null               $visitorId   Visitor, when the actor was anonymous.
	 * @param string|null            $subjectType What it happened to.
	 * @param int|null               $subjectId   Which one.
	 * @param int|null               $wpUserId    Staff member, for operator actions.
	 * @param string|null            $body        Detail, when there is any.
	 * @param array<string, mixed>   $metadata    Structured detail for the renderer.
	 * @param DateTimeImmutable|null $createdAt   When, UTC.
	 */
	public function __construct(
		public ?int $id,
		public ActivityType $type,
		public string $title,
		public ?int $leadId = null,
		public ?int $visitorId = null,
		public ?string $subjectType = null,
		public ?int $subjectId = null,
		public ?int $wpUserId = null,
		public ?string $body = null,
		public array $metadata = array(),
		public ?DateTimeImmutable $createdAt = null,
	) {
	}

	/**
	 * The page this activity concerns, when it concerns one.
	 *
	 * @return string|null
	 */
	public function url(): ?string {
		$value = $this->metadata['url'] ?? null;

		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
