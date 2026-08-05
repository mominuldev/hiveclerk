<?php
/**
 * Agent status.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Agent;

/**
 * Where a clerk is in its lifecycle.
 */
enum AgentStatus: string {

	case Draft     = 'draft';
	case Published = 'published';
	case Paused    = 'paused';
	case Archived  = 'archived';

	/**
	 * Whether this clerk should answer visitors.
	 *
	 * @return bool
	 */
	public function isServing(): bool {
		return self::Published === $this;
	}

	/**
	 * Label shown in the admin.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Draft     => 'Draft',
			self::Published => 'On duty',
			self::Paused    => 'Paused',
			self::Archived  => 'Archived',
		};
	}

	/**
	 * Parse a stored value, falling back to Draft.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Draft;
	}
}
