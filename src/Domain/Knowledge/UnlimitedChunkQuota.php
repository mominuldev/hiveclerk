<?php
/**
 * The no-limit quota.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * A quota that never refuses.
 *
 * Bound by the core provider so that ingestion always has one, and
 * replaced by the licence-backed implementation once the licence layer
 * is bound. The null object exists so `null === $this->quota` never has
 * to be written anywhere in the ingestion path — a nullable collaborator
 * is a check somebody forgets, and forgetting this one would mean no
 * cap at all.
 */
final class UnlimitedChunkQuota implements ChunkQuotaInterface {

	/**
	 * Always unlimited.
	 *
	 * @param int $indexed Chunks already stored.
	 * @return int|null
	 */
	public function remaining( int $indexed ): ?int {
		unset( $indexed );

		return null;
	}
}
