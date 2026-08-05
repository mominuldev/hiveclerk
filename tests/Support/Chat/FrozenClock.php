<?php
/**
 * A clock the test moves by hand.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Chat;

use DateTimeImmutable;
use Hiveclerk\Core\Support\ClockInterface;

/**
 * A clock the test moves by hand.
 *
 * @internal
 */
final class FrozenClock implements ClockInterface {

	/**
	 * Construct.
	 *
	 * @param DateTimeImmutable $now Current time.
	 */
	public function __construct( private DateTimeImmutable $now ) {
	}

	public function now(): DateTimeImmutable {
		return $this->now;
	}

	public function nowSql(): string {
		return $this->now->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Move time forward.
	 *
	 * @param int $seconds Seconds to advance.
	 * @return void
	 */
	public function advance( int $seconds ): void {
		$this->now = $this->now->modify( '+' . $seconds . ' seconds' );
	}
}
