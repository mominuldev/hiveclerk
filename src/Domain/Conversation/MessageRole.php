<?php
/**
 * Message author.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Conversation;

/**
 * Who wrote a message.
 */
enum MessageRole: string {

	case Visitor    = 'visitor';
	case Assistant  = 'assistant';
	case System     = 'system';
	case HumanAgent = 'human_agent';

	/**
	 * Whether this message is shown to the visitor.
	 *
	 * @return bool
	 */
	public function isVisible(): bool {
		return self::System !== $this;
	}

	/**
	 * Whether the content came from outside and must be treated as
	 * untrusted when assembling a prompt.
	 *
	 * @return bool
	 */
	public function isUntrusted(): bool {
		return self::Visitor === $this;
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::System;
	}
}
