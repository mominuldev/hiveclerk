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
 * limiting at all — WordPress's default object cache lives for one request,
 * so a counter kept only there is reset by every caller it is meant to
 * count.
 */
final class RateLimiter {

	private const GROUP = 'hiveclerk_rate';

	/**
	 * Construct.
	 *
	 * @param ClockInterface              $clock Injected so window boundaries are testable.
	 * @param RateLimitStoreInterface|null $store Durable counter, used when no
	 *                                            persistent object cache exists.
	 */
	public function __construct(
		private readonly ClockInterface $clock,
		private readonly ?RateLimitStoreInterface $store = null
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

		$hits = $this->usesStore()
			? (int) $this->store?->increment( $key, $this->stamp( $windowStart ) )
			: $this->increment( $key, $window );

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

		$hits = $this->usesStore()
			? (int) $this->store?->count( $key, $this->stamp( $windowStart ) )
			: (int) wp_cache_get( $key, self::GROUP );

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

		$key = $this->key( $bucket, $windowStart );

		wp_cache_delete( $key, self::GROUP );

		if ( $this->usesStore() ) {
			$this->store?->clear( $key );
		}
	}

	/**
	 * Delete counters for windows that have closed.
	 *
	 * Called from the nightly purge. Without it the table grows by one row
	 * per bucket per minute forever, which on a busy site is the largest
	 * table in the schema within a month — holding nothing anybody reads.
	 *
	 * @param int $keepSeconds How much recent history to leave behind.
	 * @return int Rows removed.
	 */
	public function purge( int $keepSeconds = 3600 ): int {
		if ( ! $this->usesStore() ) {
			return 0;
		}

		return (int) $this->store?->purge(
			$this->stamp( $this->clock->now()->getTimestamp() - $keepSeconds )
		);
	}

	/**
	 * Whether counting has to go through the database.
	 *
	 * WordPress ships an object cache that lives for one request. Without
	 * a persistent drop-in — which most installs do not have — every hit
	 * counts from zero, so a limiter built on it alone lets through any
	 * number of requests as long as each arrives in its own. That is the
	 * SEC-03 defence not working on precisely the hosting this product
	 * targets, so the database carries it there instead.
	 *
	 * @return bool
	 */
	private function usesStore(): bool {
		return null !== $this->store && ! wp_using_ext_object_cache();
	}

	/**
	 * A window start as a UTC MySQL DATETIME.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private function stamp( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
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
