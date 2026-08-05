<?php
/**
 * Sync attempt outcome.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

/**
 * How one attempt ended.
 *
 * `Failed` and `Retrying` are separate states because they say different
 * things to the person reading the sync log. Retrying means the plugin is
 * still working on it and there is a time in the `next_retry_at` column;
 * failed means it gave up and a human has to do something. A log that
 * showed both as "failed" would have an operator re-pushing rows that
 * were about to succeed on their own.
 */
enum SyncStatus: string {

	case Success  = 'success';
	case Retrying = 'retrying';
	case Failed   = 'failed';
	case Skipped  = 'skipped';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Success  => 'Synced',
			self::Retrying => 'Retrying',
			self::Failed   => 'Failed',
			self::Skipped  => 'Skipped',
		};
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Failed;
	}
}
