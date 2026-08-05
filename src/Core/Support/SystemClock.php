<?php
/**
 * System clock.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads the real system clock, always in UTC.
 */
final class SystemClock implements ClockInterface {

	/**
	 * Current time in UTC.
	 *
	 * @return DateTimeImmutable
	 */
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Current time as a MySQL DATETIME string in UTC.
	 *
	 * @return string
	 */
	public function nowSql(): string {
		return $this->now()->format( 'Y-m-d H:i:s' );
	}
}
