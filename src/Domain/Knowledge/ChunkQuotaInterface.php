<?php
/**
 * How much may still be indexed.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * The cap on indexed chunks, asked as a question rather than imposed.
 *
 * A port rather than a direct call into the licence layer, so that
 * ingestion — which is the part of the product that actually creates
 * chunks — does not import a billing concept. The default binding is a
 * null object that never limits anything, which is also what makes this
 * testable without a licence.
 */
interface ChunkQuotaInterface {

	/**
	 * How many more chunks may be created.
	 *
	 * @param int $indexed Chunks already stored across the site.
	 * @return int|null Null means no limit.
	 */
	public function remaining( int $indexed ): ?int;
}
