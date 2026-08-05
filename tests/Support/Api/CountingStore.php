<?php
/**
 * A rate-limit counter that behaves like the database one.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Api;

use Hiveclerk\Core\Support\RateLimitStoreInterface;

/**
 * A counter that behaves like the database one.
 *
 * @internal
 */
final class CountingStore implements RateLimitStoreInterface {

	/**
	 * Counts by bucket and window.
	 *
	 * @var array<string, int>
	 */
	public array $counts = array();

	public function increment( string $bucketKey, string $windowStart ): int {
		$key = $bucketKey . '|' . $windowStart;

		$this->counts[ $key ] = ( $this->counts[ $key ] ?? 0 ) + 1;

		return $this->counts[ $key ];
	}

	public function count( string $bucketKey, string $windowStart ): int {
		return $this->counts[ $bucketKey . '|' . $windowStart ] ?? 0;
	}

	public function clear( string $bucketKey ): void {
		foreach ( array_keys( $this->counts ) as $key ) {
			if ( str_starts_with( $key, $bucketKey . '|' ) ) {
				unset( $this->counts[ $key ] );
			}
		}
	}

	public function purge( string $before ): int {
		return 0;
	}
}
