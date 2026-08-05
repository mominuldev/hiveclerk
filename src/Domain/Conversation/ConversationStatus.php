<?php
/**
 * Conversation status.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Conversation;

/**
 * Where a conversation is in its lifecycle.
 */
enum ConversationStatus: string {

	case Active           = 'active';
	case Ended            = 'ended';
	case HandoffRequested = 'handoff_requested';
	case HandoffActive    = 'handoff_active';
	case Resolved         = 'resolved';
	case Abandoned        = 'abandoned';

	/**
	 * Whether a person is waiting on a human.
	 *
	 * @return bool
	 */
	public function needsAttention(): bool {
		return self::HandoffRequested === $this;
	}

	/**
	 * Whether the clerk should still be replying.
	 *
	 * @return bool
	 */
	public function acceptsAiReplies(): bool {
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
