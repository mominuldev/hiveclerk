<?php
/**
 * Rate limiting tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Api\AbstractController;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Core\Support\RateLimitStoreInterface;
use Hiveclerk\Tests\Support\Api\CountingStore;
use Hiveclerk\Tests\Support\Api\ThrottleProbe;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * The first line of defence against SEC-03, and the two ways it was not one.
 *
 * @internal
 */
#[CoversClass( RateLimiter::class )]
#[CoversClass( AbstractController::class )]
final class RateLimitingTest extends TestCase {

	private CountingStore $store;

	private RateLimiter $limiter;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// No persistent object cache, which is the majority of installs
		// and the case the durable counter exists for.
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->store   = new CountingStore();
		$this->limiter = new RateLimiter(
			new FrozenClock( new DateTimeImmutable( '2026-08-05 12:00:30', new DateTimeZone( 'UTC' ) ) ),
			$this->store
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testCountsSurviveBetweenRequestsWhenThereIsNoObjectCache(): void {
		// WordPress's default object cache lives for one request, so a
		// limiter built on it alone counts to one and forgets — which is
		// the whole defence not working on shared hosting.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->limiter->hit( 'chat|visitor', 5 );
		}

		self::assertSame( 3, array_sum( $this->store->counts ) );
		self::assertSame( 2, $this->limiter->peek( 'chat|visitor', 5 )->remaining );
	}

	public function testTheCeilingRefusesTheRequestAfterIt(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			self::assertTrue( $this->limiter->hit( 'chat|visitor', 3 )->allowed );
		}

		self::assertFalse( $this->limiter->hit( 'chat|visitor', 3 )->allowed );
	}

	public function testOneRequestConsumesOneUnitEvenThoughWordPressAsksTwice(): void {
		$probe = new ThrottleProbe();

		// WordPress calls a permission_callback twice: once to authorise
		// the request, and again from rest_send_allow_header() to work out
		// which methods to advertise. Without memoisation every public
		// ceiling in the product is half what it says.
		$first  = $probe->consume( $this->limiter, 'events|ip', 40 );
		$second = $probe->consume( $this->limiter, 'events|ip', 40 );

		self::assertSame( 1, array_sum( $this->store->counts ) );
		self::assertSame( $first, $second );
	}

	public function testARefusalIsAlsoReturnedTwiceWithoutCountingTwice(): void {
		$probe = new ThrottleProbe();

		$this->limiter->hit( 'events|ip', 1 );

		$first  = $probe->consume( $this->limiter, 'events|ip', 1 );
		$second = $probe->consume( $this->limiter, 'events|ip', 1 );

		self::assertInstanceOf( WP_Error::class, $first );
		self::assertSame( $first, $second );
		self::assertSame( 2, array_sum( $this->store->counts ) );
	}

	public function testAFailedCounterRefusesRatherThanWavingThrough(): void {
		$broken = new class() implements RateLimitStoreInterface {
			public function increment( string $bucketKey, string $windowStart ): int {
				return PHP_INT_MAX;
			}

			public function count( string $bucketKey, string $windowStart ): int {
				return PHP_INT_MAX;
			}

			public function clear( string $bucketKey ): void {
			}

			public function purge( string $before ): int {
				return 0;
			}
		};

		$limiter = new RateLimiter(
			new FrozenClock( new DateTimeImmutable( '2026-08-05 12:00:30', new DateTimeZone( 'UTC' ) ) ),
			$broken
		);

		// A counter that cannot count must not become a free pass on the
		// endpoint that spends the customer's provider budget.
		self::assertFalse( $limiter->hit( 'chat|visitor', 100 )->allowed );
	}
}
