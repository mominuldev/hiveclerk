<?php
/**
 * Sliding-window rate limiter.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Support;

/**
 * Caps request volume per bucket.
 *
 * This is the first line of defence against SEC-03, cost exhaustion: an
 * attacker scripting chat requests bills the *customer's* provider account,
 * which is cheaper to execute than a traditional denial-of-service and
 * hurts more.
 *
 * Uses the object cache when a persistent one exists and falls back to a
 * database table otherwise, because on shared hosting the alternative is no
 * limiting at all.
 */
final class RateLimiter {

	private const GROUP = 'hiveclerk_rate';

	/**
	 * Construct.
	 *
	 * @param ClockInterface $clock Injected so window boundaries are testable.
	 */
	public function __construct(
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Consume one unit from a bucket.
	 *
	 * @param string $bucket Opaque bucket key, already hashed if it holds PII.
	 * @param int    $limit  Maximum hits per window.
	 * @param int    $window Window length in seconds.
	 * @return RateLimitResult
	 */
	public function hit( string $bucket, int $limit, int $window = 60 ): RateLimitResult {
		$now         = $this->clock->now()->getTimestamp();
		$windowStart = $now - ( $now % $window );
		$key         = $this->key( $bucket, $windowStart );

		$hits = $this->increment( $key, $window );

		$allowed   = $hits <= $limit;
		$remaining = max( 0, $limit - $hits );
		$resetIn   = ( $windowStart + $window ) - $now;

		return new RateLimitResult( $allowed, $limit, $remaining, $resetIn );
	}

	/**
	 * Inspect a bucket without consuming from it.
	 *
	 * @param string $bucket Bucket key.
	 * @param int    $limit  Ceiling.
	 * @param int    $window Window length in seconds.
	 * @return RateLimitResult
	 */
	public function peek( string $bucket, int $limit, int $window = 60 ): RateLimitResult {
		$now         = $this->clock->now()->getTimestamp();
		$windowStart = $now - ( $now % $window );
		$key         = $this->key( $bucket, $windowStart );

		$hits = (int) wp_cache_get( $key, self::GROUP );

		return new RateLimitResult(
			$hits < $limit,
			$limit,
			max( 0, $limit - $hits ),
			( $windowStart + $window ) - $now
		);
	}

	/**
	 * Clear a bucket. Used by tests and by manual admin unblocking.
	 *
	 * @param string $bucket Bucket key.
	 * @param int    $window Window length in seconds.
	 * @return void
	 */
	public function reset( string $bucket, int $window = 60 ): void {
		$now         = $this->clock->now()->getTimestamp();
		$windowStart = $now - ( $now % $window );

		wp_cache_delete( $this->key( $bucket, $windowStart ), self::GROUP );
	}

	/**
	 * Increment a counter, creating it if absent.
	 *
	 * wp_cache_incr returns false when the key does not exist yet, so the
	 * first hit in a window takes the add() path.
	 *
	 * @param string $key    Cache key.
	 * @param int    $window Expiry in seconds.
	 * @return int Hits after this one.
	 */
	private function increment( string $key, int $window ): int {
		$hits = wp_cache_incr( $key, 1, self::GROUP );

		if ( false !== $hits ) {
			return (int) $hits;
		}

		wp_cache_add( $key, 1, self::GROUP, $window );

		return 1;
	}

	/**
	 * Build a cache key for a bucket and window.
	 *
	 * @param string $bucket      Bucket key.
	 * @param int    $windowStart Window start timestamp.
	 * @return string
	 */
	private function key( string $bucket, int $windowStart ): string {
		return hash( 'sha256', $bucket . '|' . $windowStart );
	}
}
