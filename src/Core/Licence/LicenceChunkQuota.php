<?php
/**
 * The chunk cap, backed by the licence.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Licence;

use Hiveclerk\Domain\Knowledge\ChunkQuotaInterface;

/**
 * Turns the tier's chunk limit into an answer ingestion can act on.
 *
 * A three-line adapter, and it is here rather than merged into
 * {@see LicenceGate} because the gate speaks HTTP — it returns
 * `WP_Error` — and a background job has nowhere to put one.
 */
final class LicenceChunkQuota implements ChunkQuotaInterface {

	/**
	 * Construct.
	 *
	 * @param LicenceGate $gate Entitlements.
	 */
	public function __construct(
		private readonly LicenceGate $gate
	) {
	}

	/**
	 * How many more chunks may be created.
	 *
	 * @param int $indexed Chunks already stored across the site.
	 * @return int|null
	 */
	public function remaining( int $indexed ): ?int {
		return $this->gate->chunkHeadroom( $indexed );
	}
}
