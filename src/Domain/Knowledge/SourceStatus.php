<?php
/**
 * Knowledge source status.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * Where a source is in its indexing lifecycle.
 */
enum SourceStatus: string {

	case Pending          = 'pending';
	case Processing       = 'processing';
	case Ready            = 'ready';
	case Error            = 'error';
	case NeedsReembedding = 'needs_reembedding';

	/**
	 * Whether work is in flight.
	 *
	 * @return bool
	 */
	public function isBusy(): bool {
		return self::Processing === $this;
	}

	/**
	 * Whether the operator needs to do something.
	 *
	 * @return bool
	 */
	public function needsAttention(): bool {
		return self::Error === $this || self::NeedsReembedding === $this;
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Pending;
	}
}
