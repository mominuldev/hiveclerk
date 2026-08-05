<?php
/**
 * Where and to whom a clerk is being asked to appear.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Agent;

/**
 * One page view, described in the terms a display rule can judge.
 *
 * Built on the WordPress side and passed in, so the rule engine itself
 * never asks the environment a question. That is what lets the same rules
 * be evaluated in a unit test, in a REST request and — later — in the
 * hosted product, where "the current page" is not a global at all.
 */
final class PageContext {

	/**
	 * Construct.
	 *
	 * @param string             $path       Request path, leading slash, no query string.
	 * @param string             $device     One of desktop, mobile, tablet.
	 * @param bool               $isLoggedIn Whether a WordPress user is signed in.
	 * @param array<int, string> $roles      Roles that user holds, lower-cased.
	 * @param string|null        $country    ISO 3166-1 alpha-2, when the host reports one.
	 * @param string|null        $url        Full URL, when known.
	 */
	public function __construct(
		public readonly string $path = '/',
		public readonly string $device = 'desktop',
		public readonly bool $isLoggedIn = false,
		public readonly array $roles = array(),
		public readonly ?string $country = null,
		public readonly ?string $url = null,
	) {
	}

	/**
	 * Whether the visitor holds one of the named roles.
	 *
	 * @param array<int, string> $roles Roles to test against.
	 * @return bool
	 */
	public function hasAnyRole( array $roles ): bool {
		foreach ( $roles as $role ) {
			if ( in_array( strtolower( trim( $role ) ), $this->roles, true ) ) {
				return true;
			}
		}

		return false;
	}
}
