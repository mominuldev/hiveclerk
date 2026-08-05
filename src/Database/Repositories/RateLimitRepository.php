<?php
/**
 * Rate-limit counters in the database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Core\Support\RateLimitStoreInterface;
use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;

/**
 * The rate limiter's fallback, for the majority of sites.
 *
 * Most WordPress installs have no persistent object cache. On those,
 * `wp_cache_incr()` counts within one request and forgets — so a limiter
 * built on it alone permits an unlimited number of requests as long as
 * each arrives in its own. That is the whole of the SEC-03 defence not
 * working on exactly the hosting the product targets.
 *
 * ## The counter has to be atomic
 *
 * `INSERT … ON DUPLICATE KEY UPDATE hits = hits + 1` is one statement and
 * one row lock. A read-then-write would let two simultaneous visitors both
 * read 39, both write 40, and both through — which on the endpoint that
 * spends the customer's provider budget is the case the limit exists for.
 */
final class RateLimitRepository extends AbstractRepository implements RateLimitStoreInterface {

	protected function table(): string {
		return Schema::RATE_LIMITS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'window_start' );
	}

	public function increment( string $bucketKey, string $windowStart ): int {
		$table = $this->tableName();

		$done = $this->execute(
			"INSERT INTO `{$table}` (bucket_key, window_start, hits)
			 VALUES (%s, %s, 1)
			 ON DUPLICATE KEY UPDATE hits = hits + 1",
			array( $bucketKey, $windowStart )
		);

		if ( ! $done ) {
			// A failed counter must not become a free pass. Reporting the
			// limit as reached is the safe direction: the caller refuses,
			// the visitor retries, and nothing is billed in between.
			return PHP_INT_MAX;
		}

		return $this->count( $bucketKey, $windowStart );
	}

	public function count( string $bucketKey, string $windowStart ): int {
		$table = $this->tableName();

		$prepared = $this->db->prepare(
			"SELECT hits FROM `{$table}` WHERE bucket_key = %s AND window_start = %s",
			$bucketKey,
			$windowStart
		);

		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->get_var( $prepared );
	}

	public function clear( string $bucketKey ): void {
		$table = $this->tableName();

		$this->execute( "DELETE FROM `{$table}` WHERE bucket_key = %s", array( $bucketKey ) );
	}

	public function purge( string $before ): int {
		$table = $this->tableName();

		$done = $this->execute( "DELETE FROM `{$table}` WHERE window_start < %s", array( $before ) );

		return $done ? (int) $this->db->rows_affected : 0;
	}
}
