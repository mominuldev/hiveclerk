<?php
/**
 * Mutual exclusion across concurrent requests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Support;

/**
 * A lock only one request may hold at a time.
 *
 * A port rather than a concrete class so the things that need exclusion —
 * the vector matrix rebuild, the migration runner — can be tested without
 * a database, and so the mechanism can be replaced without touching them.
 *
 * Implementations must fail *open*: a lock that cannot be evaluated
 * reports success. Every caller here is protecting an expensive operation
 * from being performed several times over, not protecting correctness, so
 * failing open costs duplicated work and failing closed costs the feature
 * entirely.
 */
interface LockInterface {

	/**
	 * Take a lock, without waiting for it.
	 *
	 * @param string $name Lock name, unique to what it guards.
	 * @return bool False only when somebody else demonstrably holds it.
	 */
	public function acquire( string $name ): bool;

	/**
	 * Release a lock.
	 *
	 * Safe to call when the lock is not held.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public function release( string $name ): void;
}
