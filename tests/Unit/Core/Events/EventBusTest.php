<?php
/**
 * Event bus tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Events;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Core\Events\EventBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass( EventBus::class )]
final class EventBusTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs( array( 'do_action' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testListenersReceiveTheEvent(): void {
		$bus      = new EventBus();
		$received = null;
		$event    = new \stdClass();

		$bus->listen(
			\stdClass::class,
			static function ( object $e ) use ( &$received ): void {
				$received = $e;
			}
		);

		$bus->dispatch( $event );

		$this->assertSame( $event, $received );
	}

	public function testListenersRunInPriorityOrder(): void {
		$bus   = new EventBus();
		$order = array();

		$bus->listen(
			\stdClass::class,
			static function () use ( &$order ): void {
				$order[] = 'late';
			},
			20
		);

		$bus->listen(
			\stdClass::class,
			static function () use ( &$order ): void {
				$order[] = 'early';
			},
			5
		);

		$bus->dispatch( new \stdClass() );

		$this->assertSame( array( 'early', 'late' ), $order );
	}

	public function testAThrowingListenerDoesNotStopTheOthers(): void {
		$bus     = new EventBus();
		$reached = false;

		$bus->listen(
			\stdClass::class,
			static function (): void {
				throw new \RuntimeException( 'listener failed' );
			},
			5
		);

		$bus->listen(
			\stdClass::class,
			static function () use ( &$reached ): void {
				$reached = true;
			},
			10
		);

		$bus->dispatch( new \stdClass() );

		$this->assertTrue( $reached, 'A failing listener must not break the dispatch chain.' );
	}

	public function testDispatchingWithNoListenersIsHarmless(): void {
		$bus   = new EventBus();
		$event = new \stdClass();

		$this->assertSame( $event, $bus->dispatch( $event ) );
	}
}
