<?php
/**
 * Sequence status.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

/**
 * Whether a sequence is sending.
 *
 * `Paused` is not `Draft`. A paused sequence keeps its enrolments and
 * resumes where each one left off; a draft has never enrolled anybody.
 * Collapsing them would mean an operator pausing a live sequence to fix a
 * typo loses everyone currently part-way through it — which is the moment
 * they stop trusting the feature.
 */
enum SequenceStatus: string {

	case Draft    = 'draft';
	case Active   = 'active';
	case Paused   = 'paused';
	case Archived = 'archived';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Draft    => 'Draft',
			self::Active   => 'Active',
			self::Paused   => 'Paused',
			self::Archived => 'Archived',
		};
	}

	/**
	 * Whether new leads may be enrolled.
	 *
	 * @return bool
	 */
	public function accepts(): bool {
		return self::Active === $this;
	}

	/**
	 * Whether due emails may go out.
	 *
	 * The same answer as `accepts()` today, and deliberately a separate
	 * question: a "stop enrolling but finish everyone already in it"
	 * state is the obvious next thing this needs, and it changes only
	 * this method.
	 *
	 * @return bool
	 */
	public function sends(): bool {
		return self::Active === $this;
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Draft;
	}
}
