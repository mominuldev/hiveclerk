<?php
/**
 * Where a clerk is allowed to appear.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Agent;

/**
 * The rules deciding whether a clerk serves a given page (FR-CLK-07).
 *
 * Four independent tests — path, device, audience, country — and a clerk
 * appears only when every one of them passes. AND rather than OR, because
 * the sentence an operator writes in their head is "on product pages, on
 * mobile, for logged-out visitors", and an OR reading of that shows the
 * clerk on every page of the site to anyone on a phone.
 *
 * Exclusions beat inclusions. "Everywhere except checkout" is the common
 * case and the expensive one to get wrong: a clerk that pops up over a
 * payment form costs the customer a sale, so the rule that removes a page
 * always wins over the rule that adds it.
 *
 * An empty rule set means "everywhere". A clerk that has never been
 * configured must serve, not hide — the alternative is a published clerk
 * that silently appears nowhere and gives the operator no signal at all.
 */
final class DisplayRules {

	/**
	 * Audience values this class understands.
	 */
	public const AUDIENCE_EVERYONE   = 'everyone';
	public const AUDIENCE_LOGGED_IN  = 'logged_in';
	public const AUDIENCE_LOGGED_OUT = 'logged_out';

	/**
	 * Devices this class understands.
	 */
	public const DEVICES = array( 'desktop', 'mobile', 'tablet' );

	/**
	 * Most patterns one list may hold.
	 *
	 * Every pattern is walked on every page view of the site, so the list
	 * is bounded. Fifty is far past any real configuration and well short
	 * of a number that would show up in a profile.
	 */
	private const MAX_PATTERNS = 50;

	/**
	 * Construct.
	 *
	 * @param array<int, string> $allow     Path patterns that admit a page; empty means all.
	 * @param array<int, string> $deny      Path patterns that reject a page.
	 * @param array<int, string> $devices   Devices allowed; empty means all.
	 * @param string             $audience  everyone, logged_in or logged_out.
	 * @param array<int, string> $roles     Roles allowed; empty means all.
	 * @param array<int, string> $countries ISO 3166-1 alpha-2 codes; empty means all.
	 */
	private function __construct(
		public readonly array $allow = array(),
		public readonly array $deny = array(),
		public readonly array $devices = array(),
		public readonly string $audience = self::AUDIENCE_EVERYONE,
		public readonly array $roles = array(),
		public readonly array $countries = array(),
	) {
	}

	/**
	 * Build from the clerk's stored JSON, ignoring anything unrecognised.
	 *
	 * @param array<string, mixed> $stored Stored display_rules column.
	 * @return self
	 */
	public static function fromArray( array $stored ): self {
		$audience = $stored['audience'] ?? self::AUDIENCE_EVERYONE;

		if ( ! is_string( $audience ) || ! in_array( $audience, self::audiences(), true ) ) {
			$audience = self::AUDIENCE_EVERYONE;
		}

		return new self(
			allow: self::patterns( $stored['include'] ?? null ),
			deny: self::patterns( $stored['exclude'] ?? null ),
			devices: self::allowed( $stored['devices'] ?? null, self::DEVICES ),
			audience: $audience,
			roles: self::strings( $stored['roles'] ?? null, 40 ),
			countries: self::countryCodes( $stored['countries'] ?? null ),
		);
	}

	/**
	 * Whether this clerk may appear on the page described.
	 *
	 * @param PageContext $context The page view.
	 * @return bool
	 */
	public function allows( PageContext $context ): bool {
		return $this->allowsPath( $context->path )
			&& $this->allowsDevice( $context->device )
			&& $this->allowsAudience( $context )
			&& $this->allowsCountry( $context->country );
	}

	/**
	 * Whether anything at all has been configured.
	 *
	 * Used by the admin to say "everywhere" rather than showing an empty
	 * list of rules, which reads as "nowhere".
	 *
	 * @return bool
	 */
	public function isUnrestricted(): bool {
		return array() === $this->allow
			&& array() === $this->deny
			&& array() === $this->devices
			&& self::AUDIENCE_EVERYONE === $this->audience
			&& array() === $this->roles
			&& array() === $this->countries;
	}

	/**
	 * The rules as they are stored and as the admin reads them back.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'include'   => $this->allow,
			'exclude'   => $this->deny,
			'devices'   => $this->devices,
			'audience'  => $this->audience,
			'roles'     => $this->roles,
			'countries' => $this->countries,
		);
	}

	/**
	 * Every audience value.
	 *
	 * @return array<int, string>
	 */
	public static function audiences(): array {
		return array( self::AUDIENCE_EVERYONE, self::AUDIENCE_LOGGED_IN, self::AUDIENCE_LOGGED_OUT );
	}

	/**
	 * Path tests.
	 *
	 * @param string $path Request path.
	 * @return bool
	 */
	private function allowsPath( string $path ): bool {
		$normalised = self::normalisePath( $path );

		foreach ( $this->deny as $pattern ) {
			if ( self::matches( $pattern, $normalised ) ) {
				return false;
			}
		}

		if ( array() === $this->allow ) {
			return true;
		}

		foreach ( $this->allow as $pattern ) {
			if ( self::matches( $pattern, $normalised ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Device test.
	 *
	 * @param string $device Reported device class.
	 * @return bool
	 */
	private function allowsDevice( string $device ): bool {
		if ( array() === $this->devices ) {
			return true;
		}

		return in_array( strtolower( $device ), $this->devices, true );
	}

	/**
	 * Audience and role test.
	 *
	 * A role list only narrows a signed-in audience. Requiring a role of a
	 * logged-out visitor is unsatisfiable, and treating it as such would
	 * turn a half-finished configuration into a clerk that appears nowhere
	 * for reasons the screen does not explain.
	 *
	 * @param PageContext $context The page view.
	 * @return bool
	 */
	private function allowsAudience( PageContext $context ): bool {
		if ( self::AUDIENCE_LOGGED_IN === $this->audience && ! $context->isLoggedIn ) {
			return false;
		}

		if ( self::AUDIENCE_LOGGED_OUT === $this->audience && $context->isLoggedIn ) {
			return false;
		}

		if ( array() === $this->roles || ! $context->isLoggedIn ) {
			return true;
		}

		return $context->hasAnyRole( $this->roles );
	}

	/**
	 * Country test.
	 *
	 * An unknown country passes. Geography is reported by a CDN header
	 * that most hosts do not send, and treating "we could not tell" as
	 * "not allowed" would hide the clerk from an entire site the moment a
	 * customer names one country they want it in.
	 *
	 * @param string|null $country Reported country code.
	 * @return bool
	 */
	private function allowsCountry( ?string $country ): bool {
		if ( array() === $this->countries || null === $country ) {
			return true;
		}

		return in_array( strtoupper( $country ), $this->countries, true );
	}

	/**
	 * Glob match, anchored at both ends.
	 *
	 * `*` is the only metacharacter; everything else is quoted. Operators
	 * write `/products/*`, not a regular expression, and accepting one
	 * would mean running a customer-supplied pattern on every page view —
	 * a catastrophic-backtracking hazard for a feature nobody asked for.
	 *
	 * @param string $pattern Configured pattern.
	 * @param string $path    Normalised request path.
	 * @return bool
	 */
	private static function matches( string $pattern, string $path ): bool {
		$expression = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';

		return 1 === preg_match( $expression, $path );
	}

	/**
	 * Reduce a configured pattern or a request path to a comparable path.
	 *
	 * A pasted full URL is accepted and reduced to its path: an operator
	 * copying an address out of the browser bar is the expected way this
	 * field gets filled in, and rejecting it would be pedantry.
	 *
	 * @param string $value Path, or a full URL.
	 * @return string
	 */
	private static function normalisePath( string $value ): string {
		$path = trim( $value );

		if ( 1 === preg_match( '#^https?://[^/]+(/.*)?$#i', $path, $match ) ) {
			$path = $match[1] ?? '/';
		}

		$query = strpos( $path, '?' );

		if ( false !== $query ) {
			$path = substr( $path, 0, $query );
		}

		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		// A trailing slash is a WordPress permalink convention, not a
		// distinction a visitor makes, so /pricing and /pricing/ are the
		// same page here.
		return '/' === $path ? '/' : rtrim( $path, '/' );
	}

	/**
	 * Clean a list of path patterns.
	 *
	 * @param mixed $value Raw stored value.
	 * @return array<int, string>
	 */
	private static function patterns( mixed $value ): array {
		return array_map(
			static fn ( string $pattern ): string => self::normalisePath( $pattern ),
			self::strings( $value, 500 )
		);
	}

	/**
	 * Clean a list of short strings.
	 *
	 * @param mixed $value Raw stored value.
	 * @param int   $limit Maximum characters per entry.
	 * @return array<int, string>
	 */
	private static function strings( mixed $value, int $limit ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}

			$trimmed = trim( $entry );

			if ( '' === $trimmed ) {
				continue;
			}

			$clean[] = substr( $trimmed, 0, $limit );

			if ( count( $clean ) >= self::MAX_PATTERNS ) {
				break;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Clean a list against a fixed vocabulary.
	 *
	 * @param mixed              $value   Raw stored value.
	 * @param array<int, string> $allowed Known values.
	 * @return array<int, string>
	 */
	private static function allowed( mixed $value, array $allowed ): array {
		$clean = array();

		foreach ( self::strings( $value, 20 ) as $entry ) {
			$lower = strtolower( $entry );

			if ( in_array( $lower, $allowed, true ) ) {
				$clean[] = $lower;
			}
		}

		// Naming every device is the same as naming none, and storing it
		// as "all three" would make the admin show three chips where the
		// operator means "anywhere".
		return count( $clean ) === count( $allowed ) ? array() : array_values( array_unique( $clean ) );
	}

	/**
	 * Clean a list of ISO country codes.
	 *
	 * @param mixed $value Raw stored value.
	 * @return array<int, string>
	 */
	private static function countryCodes( mixed $value ): array {
		$clean = array();

		foreach ( self::strings( $value, 2 ) as $entry ) {
			if ( 1 === preg_match( '/^[A-Za-z]{2}$/', $entry ) ) {
				$clean[] = strtoupper( $entry );
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
