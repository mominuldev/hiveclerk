<?php
/**
 * Durable counter contract for the rate limiter.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Support;

/**
 * Where hit counts live when the object cache cannot hold them.
 *
 * An interface rather than a direct call because {@see RateLimiter} sits
 * in `src/Core/` and nothing outside `src/Database/` may touch `$wpdb` —
 * a rule the hiveclerk.noGlobalWpdb PHPStan check enforces.
 */
interface RateLimitStoreInterface {

	/**
	 * Add one to a bucket's count for a window and return the new total.
	 *
	 * Must be atomic against concurrent requests. Two visitors hitting the
	 * same endpoint in the same millisecond is the normal case, not the
	 * edge case, and a read-then-write would let both through.
	 *
	 * @param string $bucketKey   Opaque, already-hashed bucket key.
	 * @param string $windowStart Window start as a UTC MySQL DATETIME.
	 * @return int The count after this hit.
	 */
	public function increment( string $bucketKey, string $windowStart ): int;

	/**
	 * Read a bucket's count without consuming from it.
	 *
	 * @param string $bucketKey   Bucket key.
	 * @param string $windowStart Window start as a UTC MySQL DATETIME.
	 * @return int
	 */
	public function count( string $bucketKey, string $windowStart ): int;

	/**
	 * Empty a bucket.
	 *
	 * @param string $bucketKey Bucket key.
	 * @return void
	 */
	public function clear( string $bucketKey ): void;

	/**
	 * Delete rows for windows that have closed.
	 *
	 * @param string $before Cutoff as a UTC MySQL DATETIME.
	 * @return int Rows removed.
	 */
	public function purge( string $before ): int;
}
