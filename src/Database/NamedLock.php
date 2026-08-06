<?php
/**
 * A mutual-exclusion lock held by the database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database;

use Hiveclerk\Core\Support\LockInterface;

/**
 * MySQL advisory locks, for the things only one request may do at once.
 *
 * ## Why not an option
 *
 * `add_option()` is an INSERT against a unique index and *is* exclusive
 * across processes — measured, 1 winner from 16 concurrent claims. What
 * cannot be built safely on top of it is the part that matters just as
 * much: taking a lock back from a process that died holding it.
 *
 * That needs a timestamp, and reading the timestamp back is where it
 * falls apart. A caller whose `add_option()` lost has already, inside
 * that same call, asked WordPress whether the option exists — and if it
 * asked before the winner's INSERT landed, WordPress cached the answer
 * "no such option" for the rest of the request. The loser's own re-read
 * then returns `false` from that cache rather than the timestamp from the
 * table, reads it as a corrupt lock, deletes it and takes over. Measured:
 * **5 of 16 concurrent callers each believed they held the lock.**
 *
 * `GET_LOCK()` has no such seam. Exclusion is decided by the server, and
 * the lock is scoped to the connection — so a process killed by the
 * memory limit or the execution limit releases it by disconnecting, with
 * no expiry to guess at and no stale-takeover branch to get wrong. The
 * bug above was in a branch that no longer needs to exist.
 *
 * ## Failing open
 *
 * A lock that cannot be acquired because MySQL refused the request — an
 * unusual build, a proxy that swallows `GET_LOCK` — reports success and
 * lets the caller proceed. Every user of this class is protecting an
 * expensive operation from being done several times over, not protecting
 * correctness, so the cost of failing open is the work happening twice
 * and the cost of failing closed is the feature not working at all.
 */
final class NamedLock implements LockInterface {

	/**
	 * Longest lock name MySQL accepts.
	 */
	private const MAX_NAME = 64;

	/**
	 * Prefix, so the names cannot collide with another plugin's.
	 */
	private const PREFIX = 'hvc_';

	/**
	 * Take a lock, without waiting for it.
	 *
	 * @param string $name Lock name, unique to what it guards.
	 * @return bool False only when somebody else demonstrably holds it.
	 */
	public function acquire( string $name ): bool {
		global $wpdb;

		$result = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->name( $name ) )
		);

		// '0' is a refusal; NULL is an error, and an error is not evidence
		// that anybody else holds it.
		return '0' !== (string) $result;
	}

	/**
	 * Release a lock.
	 *
	 * Safe to call when the lock is not held: MySQL answers 0 and nothing
	 * else happens.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public function release( string $name ): void {
		global $wpdb;

		$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name( $name ) )
		);
	}

	/**
	 * The server-side name for a lock.
	 *
	 * Hashed when it would not fit. MySQL rejects a name over 64
	 * characters outright rather than truncating, and a lock that throws
	 * is worse than one whose name is unreadable.
	 *
	 * @param string $name Caller's name.
	 * @return string
	 */
	private function name( string $name ): string {
		$full = self::PREFIX . $name;

		return strlen( $full ) <= self::MAX_NAME
			? $full
			: self::PREFIX . md5( $name );
	}
}
