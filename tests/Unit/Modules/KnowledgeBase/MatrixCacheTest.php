<?php
/**
 * Matrix cache tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\KnowledgeBase;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Domain\Knowledge\EmbeddingMatrix;
use Hiveclerk\Modules\KnowledgeBase\Vector\MatrixCache;
use Hiveclerk\Tests\Support\InMemoryLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A cache write that fails must not look like a cache that is merely cold.
 *
 * `wp_cache_set()` reports a refused item only in its return value, and
 * that value was discarded. Memcached caps a single item at 1 MB by
 * default, which the quantised matrix passes at roughly five thousand
 * chunks — so past that size every visitor message rebuilt the matrix from
 * a full table scan while the diagnostics reported a healthy object cache
 * with no size limit at all. The read side cannot tell the difference; the
 * write side is the only place that can.
 *
 * @internal
 */
#[CoversClass( MatrixCache::class )]
final class MatrixCacheTest extends TestCase {

	/**
	 * Stand-in for the options table.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Stand-in for the transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Whether the object cache accepts writes.
	 *
	 * @var bool
	 */
	private bool $objectCacheAccepts = true;

	/**
	 * Whether a persistent object cache is present.
	 *
	 * @var bool
	 */
	private bool $persistent = true;

	/**
	 * Stand-in for the rebuild lock.
	 *
	 * @var InMemoryLock
	 */
	private InMemoryLock $locks;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options            = array();
		$this->transients         = array();
		$this->objectCacheAccepts = true;
		$this->persistent         = true;
		$this->locks              = new InMemoryLock();

		Functions\when( 'wp_using_ext_object_cache' )->alias( fn() => $this->persistent );
		Functions\when( 'wp_cache_set' )->alias( fn() => $this->objectCacheAccepts );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		Functions\when( 'get_option' )->alias(
			fn( string $name, $fallback = false ) => $this->options[ $name ] ?? $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] = $value;

				return true;
			}
		);

		// add_option returns false when the row already exists. That is the
		// whole of the rebuild lock: an INSERT against a unique index.
		Functions\when( 'add_option' )->alias(
			function ( string $name, $value ) {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}

				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ) {
				unset( $this->options[ $name ] );

				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			fn( string $name ) => $this->transients[ $name ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value ) {
				$this->transients[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ) {
				unset( $this->transients[ $name ] );

				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The regression: a refused write reported as a healthy cache.
	 */
	public function testARefusedObjectCacheWriteIsRecordedRatherThanSwallowed(): void {
		$this->objectCacheAccepts = false;

		$cache = new MatrixCache( $this->locks );
		$cache->put( 1, 'google', 'gemini-embedding-001', $this->matrix() );

		$described = $cache->describe();

		self::assertFalse( $described['cacheable'] );
		self::assertSame( 192, $described['refused_bytes'] );
		self::assertIsString( $described['note'] );
		self::assertStringContainsString( 'rebuilt from the database on every message', $described['note'] );
	}

	/**
	 * A cache that is working says nothing, because there is nothing to say.
	 */
	public function testAnAcceptedWriteLeavesNoComplaint(): void {
		$cache = new MatrixCache( $this->locks );
		$cache->put( 1, 'google', 'gemini-embedding-001', $this->matrix() );

		$described = $cache->describe();

		self::assertTrue( $described['cacheable'] );
		self::assertNull( $described['note'] );
		self::assertNull( $described['refused_bytes'] );
	}

	/**
	 * The transient path has its own ceiling and its own explanation.
	 */
	public function testAMatrixTooLargeForATransientIsRecorded(): void {
		$this->persistent = false;

		$cache = new MatrixCache( $this->locks );
		$cache->put( 1, 'google', 'gemini-embedding-001', $this->matrix( 5 * 1024 * 1024 ) );

		$described = $cache->describe();

		self::assertFalse( $described['cacheable'] );
		self::assertIsString( $described['note'] );
		self::assertStringContainsString( 'too large for the database transient', $described['note'] );
	}

	/**
	 * Without a persistent cache and within the ceiling, the transient is used.
	 */
	public function testASmallMatrixStillReachesTheTransientFallback(): void {
		$this->persistent = false;

		$cache = new MatrixCache( $this->locks );
		$cache->put( 1, 'google', 'gemini-embedding-001', $this->matrix() );

		$described = $cache->describe();

		self::assertTrue( $described['cacheable'] );
		self::assertNotSame( array(), $this->transients );
	}

	/**
	 * Exactly one caller may rebuild a given matrix at a time.
	 *
	 * Invalidation orphans every key at once, so the moment a source
	 * finishes re-indexing every message in flight misses together and
	 * starts the same multi-megabyte scan. On shared hosting that is the
	 * site going down rather than a slow page.
	 */
	public function testOnlyOneCallerCanClaimARebuild(): void {
		$cache = new MatrixCache( $this->locks );

		self::assertTrue( $cache->claimRebuild( 1, 'google', 'gemini-embedding-001' ) );
		self::assertFalse( $cache->claimRebuild( 1, 'google', 'gemini-embedding-001' ) );
	}

	/**
	 * Releasing lets the next one through.
	 */
	public function testReleasingTheLockLetsTheNextRebuildProceed(): void {
		$cache = new MatrixCache( $this->locks );

		$cache->claimRebuild( 1, 'google', 'gemini-embedding-001' );
		$cache->releaseRebuild( 1, 'google', 'gemini-embedding-001' );

		self::assertTrue( $cache->claimRebuild( 1, 'google', 'gemini-embedding-001' ) );
	}

	/**
	 * Shards are locked one source at a time, so re-indexing one of a
	 * clerk's forty sources cannot stall a rebuild of the other
	 * thirty-nine.
	 */
	public function testTheLockIsPerSourceNotGlobal(): void {
		$cache = new MatrixCache( $this->locks );

		self::assertTrue( $cache->claimRebuild( 1, 'google', 'gemini-embedding-001' ) );
		self::assertTrue( $cache->claimRebuild( 2, 'google', 'gemini-embedding-001' ) );
	}

	/**
	 * Two pins over the same source are two different rebuilds.
	 *
	 * Re-indexing a source under a new embedding model must not be blocked
	 * by a rebuild of its old vectors, which are a separate shard.
	 */
	public function testTheLockIsPerPinAsWellAsPerSource(): void {
		$cache = new MatrixCache( $this->locks );

		self::assertTrue( $cache->claimRebuild( 1, 'google', 'gemini-embedding-001' ) );
		self::assertTrue( $cache->claimRebuild( 1, 'openai', 'text-embedding-3-small' ) );
	}

	/**
	 * A refused lock is reported, not swallowed.
	 *
	 * The caller degrades to keyword-only on a false here. If this ever
	 * returned true on refusal, every request would rebuild again and the
	 * stampede would be back with the lock still in place.
	 */
	public function testARefusalIsReportedToTheCaller(): void {
		$this->locks->refuseEverything = true;

		$cache = new MatrixCache( $this->locks );

		self::assertFalse( $cache->claimRebuild( 1, 'google', 'gemini-embedding-001' ) );
	}

	/*
	 * There is deliberately no test here for a lock left behind by a
	 * process that died. It used to need one, because the lock was an
	 * option with an expiry and the takeover logic was ours to get wrong —
	 * which it was. A MySQL advisory lock is scoped to the connection, so
	 * a process that dies releases it by disconnecting and there is no
	 * takeover branch left to test. That the exclusion actually holds
	 * across processes is not something a fake can show, and is measured
	 * against a real database instead.
	 */

	/**
	 * A matrix of a given size.
	 *
	 * @param int $bytes How many bytes of quantised vector.
	 * @return EmbeddingMatrix
	 */
	private function matrix( int $bytes = 192 ): EmbeddingMatrix {
		return new EmbeddingMatrix( array( 1 ), str_repeat( "\x0f", $bytes ), 192 );
	}
}
