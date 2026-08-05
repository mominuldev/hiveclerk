<?php
/**
 * What a usage event was for.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Usage;

/**
 * The kind of work a provider call did.
 *
 * Recorded separately because the cost profiles are not comparable.
 * Embedding is a one-off cost per document that never repeats; chat is a
 * recurring cost per conversation. A single blended "spend" figure hides
 * the one number an operator can actually act on — what each answered
 * question costs them.
 */
enum UsageKind: string {

	case Chat      = 'chat';
	case Embedding = 'embedding';
	case Summary   = 'summary';
	case Verify    = 'verify';

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Chat      => 'Conversation',
			self::Embedding => 'Indexing',
			self::Summary   => 'Summary',
			self::Verify    => 'Connection check',
		};
	}

	/**
	 * Whether this kind is charged to a visitor conversation.
	 *
	 * Indexing is charged to the site, not to any one conversation, so
	 * cost-per-conversation must exclude it or every figure is wrong on
	 * the day a knowledge base is re-indexed.
	 *
	 * @return bool
	 */
	public function isConversational(): bool {
		return self::Chat === $this || self::Summary === $this;
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Chat;
	}
}
