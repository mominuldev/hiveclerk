<?php
/**
 * Container tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Container;

use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Container\ContainerException;
use Hiveclerk\Core\Container\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 */
#[CoversClass( Container::class )]
final class ContainerTest extends TestCase {

	public function testBindReturnsANewInstanceEachTime(): void {
		$container = new Container();
		$container->bind( 'thing', static fn (): stdClass => new stdClass() );

		$this->assertNotSame( $container->get( 'thing' ), $container->get( 'thing' ) );
	}

	public function testSingletonReturnsTheSameInstance(): void {
		$container = new Container();
		$container->singleton( 'thing', static fn (): stdClass => new stdClass() );

		$this->assertSame( $container->get( 'thing' ), $container->get( 'thing' ) );
	}

	public function testInstanceStoresAPrebuiltObject(): void {
		$container = new Container();
		$object    = new stdClass();
		$container->instance( 'thing', $object );

		$this->assertSame( $object, $container->get( 'thing' ) );
	}

	public function testAliasResolvesToTheTarget(): void {
		$container = new Container();
		$container->singleton( 'concrete', static fn (): stdClass => new stdClass() );
		$container->alias( 'contract', 'concrete' );

		$this->assertSame( $container->get( 'concrete' ), $container->get( 'contract' ) );
	}

	public function testHasReportsRegistration(): void {
		$container = new Container();

		$this->assertFalse( $container->has( 'thing' ) );

		$container->bind( 'thing', static fn (): stdClass => new stdClass() );

		$this->assertTrue( $container->has( 'thing' ) );
	}

	public function testUnknownIdentifierThrowsNotFound(): void {
		$container = new Container();

		$this->expectException( NotFoundException::class );
		$container->get( 'missing' );
	}

	public function testCircularDependencyIsReportedRatherThanExhaustingMemory(): void {
		$container = new Container();
		$container->bind( 'a', static fn ( Container $c ): mixed => $c->get( 'b' ) );
		$container->bind( 'b', static fn ( Container $c ): mixed => $c->get( 'a' ) );

		$this->expectException( ContainerException::class );
		$this->expectExceptionMessageMatches( '/Circular dependency/' );

		$container->get( 'a' );
	}

	public function testResolutionStateIsClearedAfterAFailedResolve(): void {
		$container = new Container();
		$container->bind(
			'explodes',
			static function (): mixed {
				throw new \RuntimeException( 'boom' );
			}
		);

		try {
			$container->get( 'explodes' );
		} catch ( \RuntimeException ) {
			// Expected on the first attempt.
		}

		// A second attempt must fail the same way, not report a false cycle.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'boom' );
		$container->get( 'explodes' );
	}
}
