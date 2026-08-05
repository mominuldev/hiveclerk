<?php
/**
 * Clock contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Support;

use DateTimeImmutable;

/**
 * Supplies the current time.
 *
 * Injected everywhere rather than calling time() directly, so no test has
 * to depend on the wall clock.
 */
interface ClockInterface {

	/**
	 * Current time in UTC.
	 *
	 * @return DateTimeImmutable
	 */
	public function now(): DateTimeImmutable;

	/**
	 * Current time as a MySQL DATETIME string in UTC.
	 *
	 * @return string
	 */
	public function nowSql(): string;
}
