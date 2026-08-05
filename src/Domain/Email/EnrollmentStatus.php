<?php
/**
 * Enrolment status.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

/**
 * Where one lead is inside one sequence.
 *
 * `Exited` and `Completed` are different facts and reporting needs both.
 * Completed means every step sent and nobody replied; exited means
 * something stopped it — usually the lead answering, which is the outcome
 * the sequence existed to produce. A metric that counted them together
 * would show a sequence performing best when it was ignored longest.
 */
enum EnrollmentStatus: string {

	case Active    = 'active';
	case Completed = 'completed';
	case Exited    = 'exited';
	case Failed    = 'failed';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Active    => 'In progress',
			self::Completed => 'Finished',
			self::Exited    => 'Exited early',
			self::Failed    => 'Stopped after a failure',
		};
	}

	/**
	 * Whether more email is due.
	 *
	 * @return bool
	 */
	public function isOpen(): bool {
		return self::Active === $this;
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Active;
	}
}
