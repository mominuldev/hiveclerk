<?php
/**
 * Caching for the quantised matrix.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Vector;

use Hiveclerk\Domain\Knowledge\EmbeddingMatrix;

/**
 * Keeps the stage-1 matrix out of the database on the hot path.
 *
 * Rebuilding it means reading every embedding row in the source set —
 * about 1.9 MB and ten thousand rows at the size this product targets.
 * Doing that per query is the single largest avoidable cost in retrieval,
 * so it is cached; but the cache has to survive two hosting realities:
 *
 * **There may be no persistent object cache.** On a host without Redis or
 * Memcached, `wp_cache_set()` is per-request and every query rebuilds.
 * The fallback is a transient, which is a database row — slower than an
 * object cache and much faster than the rebuild. The status page reports
 * which one is in play, because the performance difference is real and is
 * otherwise invisible to the person who could fix it.
 *
 * **Invalidation must be cheap and cannot be exhaustive.** A source set
 * is any combination of sources, so there is no bounded list of keys to
 * delete when one source re-indexes. Keys therefore carry a per-source
 * generation number: bumping it makes every key mentioning that source
 * unreachable at once, without a scan and without needing to know which
 * combinations were ever cached. The orphaned entries expire on their own.
 */
final class MatrixCache {

	/**
	 * Object cache group.
	 */
	private const GROUP = 'hiveclerk_matrix';

	/**
	 * Transient prefix for the fallback path.
	 */
	private const TRANSIENT_PREFIX = 'hvc_mtx_';

	/**
	 * Option holding per-source generation numbers.
	 */
	private const GENERATION_OPTION = 'hiveclerk_matrix_generation';

	/**
	 * How long a cached matrix lives.
	 */
	private const TTL = DAY_IN_SECONDS;

	/**
	 * Largest base64 payload worth writing to a transient, in bytes.
	 *
	 * A transient is an option row, and an option row is read whole. Past
	 * a few megabytes the read costs more than rebuilding from the
	 * embeddings table — and on hosts with a low `max_allowed_packet` the
	 * write fails silently, which is worse than not attempting it, because
	 * a failed write looks exactly like a cache miss forever.
	 *
	 * Compared against the *encoded* length, which is a third larger than
	 * the matrix. In practice this caps the fallback at roughly 16,000
	 * chunks of 1,536 dimensions; above that a site needs a persistent
	 * object cache, and the status page says so.
	 */
	private const MAX_TRANSIENT_BYTES = 4194304;

	/**
	 * Matrices already loaded this request.
	 *
	 * @var array<string, EmbeddingMatrix>
	 */
	private array $memo = array();

	/**
	 * Where the last read came from.
	 *
	 * @var string
	 */
	private string $lastSource = 'database';

	/**
	 * Read a cached matrix.
	 *
	 * @param array<int, int> $sourceIds Sources.
	 * @param string          $provider  Pinned provider.
	 * @param string          $model     Pinned model.
	 * @return EmbeddingMatrix|null
	 */
	public function get( array $sourceIds, string $provider, string $model ): ?EmbeddingMatrix {
		$key = $this->key( $sourceIds, $provider, $model );

		if ( isset( $this->memo[ $key ] ) ) {
			$this->lastSource = 'request';

			return $this->memo[ $key ];
		}

		$found  = false;
		$cached = wp_cache_get( $key, self::GROUP, false, $found );
		$origin = 'object_cache';

		if ( ! $found || ! is_array( $cached ) ) {
			$cached = get_transient( self::TRANSIENT_PREFIX . $key );
			$origin = 'transient';
		}

		if ( ! is_array( $cached ) ) {
			$this->lastSource = 'database';

			return null;
		}

		$matrix = $this->hydrate( $cached );

		// A cached matrix whose row count and byte length disagree was
		// truncated in transit. Scanning it would answer from the first few
		// thousand chunks and report nothing wrong, so it is discarded and
		// rebuilt instead.
		if ( null === $matrix || ! $matrix->isConsistent() ) {
			$this->forgetKey( $key );
			$this->lastSource = 'database';

			return null;
		}

		$this->memo[ $key ] = $matrix;
		$this->lastSource   = $origin;

		return $matrix;
	}

	/**
	 * Store a matrix.
	 *
	 * @param array<int, int> $sourceIds Sources.
	 * @param string          $provider  Pinned provider.
	 * @param string          $model     Pinned model.
	 * @param EmbeddingMatrix $matrix    Matrix.
	 * @return void
	 */
	public function put( array $sourceIds, string $provider, string $model, EmbeddingMatrix $matrix ): void {
		$key = $this->key( $sourceIds, $provider, $model );

		$this->memo[ $key ] = $matrix;

		if ( $this->isPersistent() ) {
			wp_cache_set(
				$key,
				array(
					'ids'   => $matrix->ids,
					'bits'  => $matrix->bits,
					'width' => $matrix->width,
				),
				self::GROUP,
				self::TTL
			);

			return;
		}

		/*
		 * Base64 for the transient path, and it is not optional.
		 *
		 * A transient with no persistent object cache is an option row, and
		 * an option row is a utf8mb4 LONGTEXT column. The quantised matrix
		 * is arbitrary binary, so `wpdb::strip_invalid_text_for_column()`
		 * removes the byte sequences that are not valid UTF-8 — which
		 * shortens the string inside an already-serialised payload, so
		 * `unserialize()` then fails on a length prefix that no longer
		 * matches. The failure is silent in both directions: the write
		 * reports success and the read reports a cache miss, so the matrix
		 * is rebuilt from the database on every single request and nothing
		 * anywhere says so.
		 *
		 * Measured before assuming: a 4 KB random payload written through
		 * set_transient() came back as `false`.
		 *
		 * The 33% inflation is the price of the fallback working at all.
		 * The ceiling below is applied to the encoded size for that reason.
		 */
		$encoded = base64_encode( $matrix->bits );

		if ( strlen( $encoded ) > self::MAX_TRANSIENT_BYTES ) {
			return;
		}

		set_transient(
			self::TRANSIENT_PREFIX . $key,
			array(
				'ids'      => $matrix->ids,
				'bits'     => $encoded,
				'width'    => $matrix->width,
				'encoding' => 'base64',
			),
			self::TTL
		);
	}

	/**
	 * Invalidate everything that mentions these sources.
	 *
	 * @param array<int, int> $sourceIds Sources. Empty invalidates all.
	 * @return void
	 */
	public function forget( array $sourceIds = array() ): void {
		$this->memo = array();

		$generations = $this->generations();

		if ( array() === $sourceIds ) {
			// Bumping every known generation is O(sources), not O(cached
			// combinations), and the combinations are unbounded.
			foreach ( array_keys( $generations ) as $sourceId ) {
				$generations[ $sourceId ] = ( $generations[ $sourceId ] ?? 0 ) + 1;
			}

			$generations['*'] = ( $generations['*'] ?? 0 ) + 1;
		} else {
			foreach ( $sourceIds as $sourceId ) {
				$id                 = (string) (int) $sourceId;
				$generations[ $id ] = ( $generations[ $id ] ?? 0 ) + 1;
			}
		}

		update_option( self::GENERATION_OPTION, $generations, false );
	}

	/**
	 * Where the most recent read came from.
	 *
	 * @return string
	 */
	public function lastSource(): string {
		return $this->lastSource;
	}

	/**
	 * Whether a persistent object cache is available.
	 *
	 * @return bool
	 */
	public function isPersistent(): bool {
		return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
	}

	/**
	 * How this cache is currently behaving.
	 *
	 * @return array<string, mixed>
	 */
	public function describe(): array {
		$persistent = $this->isPersistent();

		return array(
			'backend'       => $persistent ? 'object_cache' : 'transient',
			'persistent'    => $persistent,
			'ttl'           => self::TTL,
			'max_cacheable' => $persistent ? null : self::MAX_TRANSIENT_BYTES,
			'note'          => $persistent
				? null
				: 'No persistent object cache was found. The vector index falls back to a '
					. 'database transient, which adds roughly 10–40 ms to each search and '
					. 'stops working above about 16,000 chunks — past that the index is '
					. 'rebuilt on every message. Installing Redis or Memcached removes both limits.',
		);
	}

	/**
	 * Build a cache key.
	 *
	 * @param array<int, int> $sourceIds Sources.
	 * @param string          $provider  Provider.
	 * @param string          $model     Model.
	 * @return string
	 */
	private function key( array $sourceIds, string $provider, string $model ): string {
		$ids = array_values( array_unique( array_map( 'intval', $sourceIds ) ) );
		sort( $ids );

		$generations = $this->generations();
		$stamp       = (string) ( $generations['*'] ?? 0 );

		foreach ( $ids as $id ) {
			$stamp .= '.' . ( $generations[ (string) $id ] ?? 0 );
		}

		// Hashed rather than concatenated: an agency site can assign forty
		// sources to a clerk, and the object cache key length limit on
		// Memcached is 250 bytes.
		return substr(
			md5( implode( ',', $ids ) . '|' . $provider . '|' . $model . '|' . $stamp ),
			0,
			24
		);
	}

	/**
	 * Per-source generation numbers.
	 *
	 * @return array<string, int>
	 */
	private function generations(): array {
		$stored = get_option( self::GENERATION_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$generations = array();

		foreach ( $stored as $key => $value ) {
			if ( is_numeric( $value ) ) {
				$generations[ (string) $key ] = (int) $value;
			}
		}

		return $generations;
	}

	/**
	 * Rebuild a matrix from a cached payload.
	 *
	 * @param array<mixed> $payload Cached value.
	 * @return EmbeddingMatrix|null
	 */
	private function hydrate( array $payload ): ?EmbeddingMatrix {
		$ids   = $payload['ids'] ?? null;
		$bits  = $payload['bits'] ?? null;
		$width = $payload['width'] ?? null;

		if ( ! is_array( $ids ) || ! is_string( $bits ) || ! is_numeric( $width ) ) {
			return null;
		}

		if ( 'base64' === ( $payload['encoding'] ?? null ) ) {
			// Strict: a corrupted payload must decode to false and be
			// rebuilt, not to a shorter string that passes for a matrix.
			$decoded = base64_decode( $bits, true );

			if ( ! is_string( $decoded ) ) {
				return null;
			}

			$bits = $decoded;
		}

		return new EmbeddingMatrix(
			array_values( array_map( 'intval', $ids ) ),
			$bits,
			(int) $width
		);
	}

	/**
	 * Drop one key from both backends.
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	private function forgetKey( string $key ): void {
		unset( $this->memo[ $key ] );

		wp_cache_delete( $key, self::GROUP );
		delete_transient( self::TRANSIENT_PREFIX . $key );
	}
}
