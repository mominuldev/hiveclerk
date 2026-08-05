<?php
/**
 * Score event source.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

/**
 * Who or what awarded a set of points.
 *
 * Displayed under every line of the breakdown. A sales team that cannot
 * tell a rule they wrote from a model's opinion will trust neither.
 */
enum ScoreSource: string {

	case Rule   = 'rule';
	case Ai     = 'ai';
	case Manual = 'manual';

	/**
	 * Whether an event from this source has to carry a written rationale.
	 *
	 * A rule explains itself: its label is the reason. A model's
	 * adjustment does not, and an unexplained number from a model is
	 * exactly what FR-LED-04 exists to prevent.
	 *
	 * @return bool
	 */
	public function requiresRationale(): bool {
		return self::Ai === $this;
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Rule   => 'Rule',
			self::Ai     => 'AI',
			self::Manual => 'Manual',
		};
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Rule;
	}
}
