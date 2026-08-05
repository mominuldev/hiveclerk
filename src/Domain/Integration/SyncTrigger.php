<?php
/**
 * When a lead is pushed.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

/**
 * The "push when" control at the bottom of D11 §8.
 *
 * Deliberately not "always". A CRM that receives every anonymous visitor
 * who typed an address into a chat window becomes a list nobody trusts,
 * and the customer pays per contact in most of the products this pushes
 * into. The default is `Qualified` for that reason.
 */
enum SyncTrigger: string {

	case Captured   = 'captured';
	case Qualified  = 'qualified';
	case ScoreAbove = 'score_above';
	case StageMoved = 'stage_moved';
	case Manual     = 'manual';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Captured   => 'Lead captured',
			self::Qualified  => 'Lead qualified',
			self::ScoreAbove => 'Score above a threshold',
			self::StageMoved => 'Pipeline stage changed',
			self::Manual     => 'Only when pushed by hand',
		};
	}

	/**
	 * Parse a stored value, defaulting to the conservative choice.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Qualified;
	}
}
