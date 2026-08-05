<?php
/**
 * Enrolment trigger.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

/**
 * What puts a lead into a sequence (FR-EML-02).
 *
 * `ConversationAbandoned` is the one that pays for the feature. Somebody
 * who asked three questions and left without giving an address is not
 * reachable; somebody who gave an address and *then* left is the most
 * valuable follow-up a site has, and nothing else in the product notices
 * they went quiet.
 */
enum TriggerType: string {

	case LeadCreated           = 'lead_created';
	case ScoreThreshold        = 'score_threshold';
	case StageChanged          = 'stage_changed';
	case ConversationAbandoned = 'conversation_abandoned';
	case Manual                = 'manual';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::LeadCreated           => 'Lead created',
			self::ScoreThreshold        => 'Score reaches a threshold',
			self::StageChanged          => 'Pipeline stage changed',
			self::ConversationAbandoned => 'Conversation abandoned',
			self::Manual                => 'Enrolled by hand',
		};
	}

	/**
	 * Whether this trigger needs a number alongside it.
	 *
	 * @return bool
	 */
	public function needsThreshold(): bool {
		return self::ScoreThreshold === $this;
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Manual;
	}
}
