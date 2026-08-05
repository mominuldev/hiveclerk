<?php
/**
 * Exit condition.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

/**
 * What takes a lead back out of a sequence (FR-EML-04).
 *
 * ## Replying always exits, and it is not configurable
 *
 * A follow-up sequence that keeps sending after the person answered is
 * the single most damaging thing an email feature can do — it is visible
 * to the recipient, it is obviously automated, and it undoes the
 * conversation that just started. `Replied` is therefore not in this list
 * as an option to switch on; the engine enforces it for every sequence
 * and this enum covers the conditions a customer chooses on top.
 */
enum ExitCondition: string {

	case StageReached = 'stage_reached';
	case StatusIs     = 'status_is';
	case ScoreAbove   = 'score_above';
	case Unsubscribed = 'unsubscribed';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::StageReached => 'Reaches a pipeline stage',
			self::StatusIs     => 'Status becomes',
			self::ScoreAbove   => 'Score goes above',
			self::Unsubscribed => 'Unsubscribes',
		};
	}

	/**
	 * Whether the condition carries a value.
	 *
	 * @return bool
	 */
	public function needsValue(): bool {
		return self::Unsubscribed !== $this;
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self|null
	 */
	public static function fromStorage( ?string $value ): ?self {
		return self::tryFrom( (string) $value );
	}
}
