<?php
/**
 * Caching for the quantised matrix.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Vector;

use Hiveclerk\Core\Support\LockInterface;
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
	 * Records that the last write had nowhere to go.
	 *
	 * A refused write and a cold cache look identical from the read side —
	 * both are a miss — so without this the index is rebuilt on every
	 * message and the only symptom is that the site is slow. The flag is
	 * written only when a write actually fails and expires on its own, so
	 * a host that gains an object cache stops reporting the problem within
	 * a day without anything having to clear it.
	 */
	private const REFUSED_TRANSIENT = 'hvc_mtx_refused';

	/**
	 * Lock name prefix for a shard rebuild.
	 */
	private const LOCK_PREFIX = 'mtx_';

	/**
	 * Matrices already loaded this request.
	 *
	 * @var array<string, EmbeddingMatrix>
	 */
	private array $memo = array();

	/**
	 * Construct.
	 *
	 * @param LockInterface $locks Rebuild mutual exclusion.
	 */
	public function __construct( private readonly LockInterface $locks ) {
	}

	/**
	 * Where the last read came from.
	 *
	 * @var string
	 */
	private string $lastSource = 'database';

	/**
	 * Read one source's cached shard.
	 *
	 * @param int    $sourceId Source.
	 * @param string $provider Pinned provider.
	 * @param string $model    Pinned model.
	 * @return EmbeddingMatrix|null
	 */
	public function get( int $sourceId, string $provider, string $model ): ?EmbeddingMatrix {
		$key = $this->key( $sourceId, $provider, $model );

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
	 * Store one source's shard.
	 *
	 * @param int             $sourceId Source.
	 * @param string          $provider Pinned provider.
	 * @param string          $model    Pinned model.
	 * @param EmbeddingMatrix $matrix   Shard.
	 * @return void
	 */
	public function put( int $sourceId, string $provider, string $model, EmbeddingMatrix $matrix ): void {
		$key = $this->key( $sourceId, $provider, $model );

		$this->memo[ $key ] = $matrix;

		if ( $this->isPersistent() ) {
			$stored = wp_cache_set(
				$key,
				array(
					'ids'   => $matrix->ids,
					'bits'  => $matrix->bits,
					'width' => $matrix->width,
				),
				self::GROUP,
				self::TTL
			);

			if ( false !== $stored ) {
				return;
			}

			/*
			 * The backend refused the item. Memcached caps a single value
			 * at 1 MB by default, which a matrix passes at roughly five
			 * thousand chunks — and it reports that only in this return
			 * value, which was previously discarded. Every message then
			 * rebuilt the matrix from a full table scan while the status
			 * screen reported a healthy object cache.
			 *
			 * There is no second attempt to make: with a persistent object
			 * cache `set_transient()` routes to the same backend and would
			 * be refused identically. So the failure is recorded instead,
			 * and the operator is told what to change.
			 */
			$this->recordRefusal( 'object_cache', strlen( $matrix->bits ) );

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
			$this->recordRefusal( 'transient', strlen( $matrix->bits ) );

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
	 * Become the one process that rebuilds this matrix.
	 *
	 * Invalidation is a generation bump, which orphans every key at once
	 * and costs nothing — so the moment a source finishes re-indexing,
	 * every visitor message in flight misses the cache together and starts
	 * the same multi-megabyte scan. At ten thousand chunks that is 128 ms
	 * and tens of megabytes each; at fifty thousand it is 1.1 seconds and
	 * 113 MB each. On shared hosting with a handful of PHP workers, ten
	 * concurrent messages during a re-index is not a slow page, it is the
	 * site going down.
	 *
	 * Held per source, so re-indexing one of a clerk's forty sources
	 * blocks a rebuild of that one and nothing else.
	 *
	 * See {@see \Hiveclerk\Database\NamedLock} for why this is a MySQL advisory lock and not
	 * an option — an option is exclusive but cannot safely be taken back
	 * from a process that died holding it, and the attempt to do that had
	 * five of sixteen concurrent callers each believing they had won.
	 *
	 * @param int    $sourceId Source.
	 * @param string $provider Pinned provider.
	 * @param string $model    Pinned model.
	 * @return bool False when somebody else is already rebuilding it.
	 */
	public function claimRebuild( int $sourceId, string $provider, string $model ): bool {
		return $this->locks->acquire(
			self::LOCK_PREFIX . $this->key( $sourceId, $provider, $model )
		);
	}

	/**
	 * Release the rebuild lock.
	 *
	 * @param int    $sourceId Source.
	 * @param string $provider Pinned provider.
	 * @param string $model    Pinned model.
	 * @return void
	 */
	public function releaseRebuild( int $sourceId, string $provider, string $model ): void {
		$this->locks->release(
			self::LOCK_PREFIX . $this->key( $sourceId, $provider, $model )
		);
	}

	/**
	 * Forget a source that no longer exists.
	 *
	 * Different from invalidating it. Invalidation bumps the source's
	 * generation so its shard key changes, which is right while the source
	 * is still there and will be rebuilt. A deleted source is never
	 * rebuilt, so its counter would sit in the option for the life of the
	 * install — one entry per source ever created, read on every retrieval
	 * to build a key and rewritten whole on every invalidation.
	 *
	 * Dropping the entry returns the source to generation zero, which is
	 * safe because the ids are auto-increment and never reissued: no
	 * future source can be handed the number and find a stale shard
	 * waiting under it.
	 *
	 * @param int $sourceId Source that has been deleted.
	 * @return void
	 */
	public function forgetSource( int $sourceId ): void {
		$this->memo = array();

		$drop = (string) $sourceId;
		$kept = array();

		foreach ( $this->generations() as $key => $value ) {
			if ( $key !== $drop ) {
				$kept[ $key ] = $value;
			}
		}

		update_option( self::GENERATION_OPTION, $kept, false );
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
		$refusal    = $this->lastRefusal();

		return array(
			'backend'       => $persistent ? 'object_cache' : 'transient',
			'persistent'    => $persistent,
			'ttl'           => self::TTL,
			'max_cacheable' => $persistent ? null : self::MAX_TRANSIENT_BYTES,
			'cacheable'     => null === $refusal,
			'refused_bytes' => $refusal['bytes'] ?? null,
			'note'          => $this->note( $persistent, $refusal ),
		);
	}

	/**
	 * What an operator should be told about the cache right now.
	 *
	 * @param bool                                  $persistent Whether a persistent backend is in play.
	 * @param array{backend: string, bytes: int}|null $refusal  The last refused write, if any.
	 * @return string|null
	 */
	private function note( bool $persistent, ?array $refusal ): ?string {
		if ( null !== $refusal && 'object_cache' === $refusal['backend'] ) {
			return 'The vector index is larger than this object cache will accept a single '
				. 'value of, so it is rebuilt from the database on every message. Memcached '
				. 'caps an item at 1 MB by default; Redis does not. Raising that limit, '
				. 'switching to Redis, or giving a clerk fewer sources restores caching.';
		}

		if ( null !== $refusal ) {
			return 'The vector index is too large for the database transient this host falls '
				. 'back to, so it is rebuilt on every message. Installing Redis or Memcached '
				. 'removes the limit.';
		}

		if ( $persistent ) {
			return null;
		}

		return 'No persistent object cache was found. The vector index falls back to a '
			. 'database transient, which adds roughly 10–40 ms to each search and '
			. 'stops working above about 16,000 chunks — past that the index is '
			. 'rebuilt on every message. Installing Redis or Memcached removes both limits.';
	}

	/**
	 * Record that a matrix had nowhere to be written.
	 *
	 * @param string $backend Which backend refused it.
	 * @param int    $bytes   Size of the matrix that was refused.
	 * @return void
	 */
	private function recordRefusal( string $backend, int $bytes ): void {
		set_transient(
			self::REFUSED_TRANSIENT,
			array(
				'backend' => $backend,
				'bytes'   => $bytes,
			),
			self::TTL
		);
	}

	/**
	 * The last refused write, if one is still on record.
	 *
	 * @return array{backend: string, bytes: int}|null
	 */
	private function lastRefusal(): ?array {
		$stored = get_transient( self::REFUSED_TRANSIENT );

		if ( ! is_array( $stored ) || ! isset( $stored['backend'], $stored['bytes'] ) ) {
			return null;
		}

		return array(
			'backend' => (string) $stored['backend'],
			'bytes'   => (int) $stored['bytes'],
		);
	}

	/**
	 * Build a shard's cache key.
	 *
	 * Keyed on one source rather than a set. A set-shaped key made the
	 * number of possible entries the number of source *combinations*, so
	 * two clerks sharing nine of ten sources cached the overlap twice, and
	 * every combination mentioning a re-indexed source had to be orphaned
	 * at once. Per source there is one entry per source, and re-indexing
	 * invalidates exactly one of them.
	 *
	 * @param int    $sourceId Source.
	 * @param string $provider Provider.
	 * @param string $model    Model.
	 * @return string
	 */
	private function key( int $sourceId, string $provider, string $model ): string {
		$generations = $this->generations();

		$stamp = (string) ( $generations['*'] ?? 0 )
			. '.' . ( $generations[ (string) $sourceId ] ?? 0 );

		// Hashed rather than concatenated: Memcached caps a key at 250
		// bytes and a provider and model name are not short.
		return substr(
			md5( $sourceId . '|' . $provider . '|' . $model . '|' . $stamp ),
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
