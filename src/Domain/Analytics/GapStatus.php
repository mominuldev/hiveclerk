<?php
/**
 * What has been done about a knowledge gap.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Analytics;

/**
 * The three states a gap can be in.
 *
 * `Ignored` is separate from `Resolved` and both are separate from
 * deletion. A gap the operator has decided is not worth answering must
 * stop appearing in the worklist without pretending it was answered, and
 * it must not be re-created the next time somebody asks — which deleting
 * the row would allow, because detection matches on the query hash.
 */
enum GapStatus: string {

	case Open     = 'open';
	case Resolved = 'resolved';
	case Ignored  = 'ignored';

	/**
	 * Parse a stored value, defaulting to open.
	 *
	 * @param string|null $value Raw column value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Open;
	}

	/**
	 * Whether this gap still wants a person.
	 *
	 * @return bool
	 */
	public function isOpen(): bool {
		return self::Open === $this;
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Open     => 'Open',
			self::Resolved => 'Answered',
			self::Ignored  => 'Ignored',
		};
	}
}
