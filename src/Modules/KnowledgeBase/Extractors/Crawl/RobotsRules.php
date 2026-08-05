<?php
/**
 * robots.txt parsing.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors\Crawl;

/**
 * The rules a site publishes for automated readers.
 *
 * Obeyed because it is the agreement crawlers operate under, and because
 * a plugin that ignores it will be the reason a customer's IP is blocked
 * by their own host. The customer usually owns the site being crawled,
 * which makes it tempting to skip — but "usually" is doing a lot of work
 * in that sentence, and a crawl source accepts any URL.
 */
final class RobotsRules {

	/**
	 * Construct.
	 *
	 * @param array<int, string> $disallow  Disallowed path prefixes.
	 * @param array<int, string> $allow     Allowed path prefixes, which win
	 *                                      over a longer disallow.
	 * @param float              $crawlDelay Seconds requested between hits.
	 * @param array<int, string> $sitemaps  Declared sitemap URLs.
	 */
	private function __construct(
		private readonly array $disallow,
		private readonly array $allow,
		public readonly float $crawlDelay,
		public readonly array $sitemaps,
	) {
	}

	/**
	 * Permit everything.
	 *
	 * Used when robots.txt is absent or unreadable. A missing file means
	 * no restrictions, which is what the specification says and also the
	 * safer default here — refusing to crawl because a file is missing
	 * would break the common case to protect against the rare one.
	 *
	 * @return self
	 */
	public static function permissive(): self {
		return new self( array(), array(), 0.0, array() );
	}

	/**
	 * Parse a robots.txt body.
	 *
	 * @param string $body      File contents.
	 * @param string $userAgent Our user agent token.
	 * @return self
	 */
	public static function parse( string $body, string $userAgent ): self {
		$disallow = array();
		$allow    = array();
		$delay    = 0.0;
		$sitemaps = array();

		// Which group applies: a block naming us beats the wildcard block,
		// and a block naming someone else is ignored entirely.
		$applies  = false;
		$specific = false;

		$lines = preg_split( '/\R/', $body );

		foreach ( false === $lines ? array() : $lines as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) ?? $line );

			if ( '' === $line || ! str_contains( $line, ':' ) ) {
				continue;
			}

			[ $field, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );

			$field = strtolower( $field );

			// Sitemaps are declared outside any group and apply globally.
			if ( 'sitemap' === $field && '' !== $value ) {
				$sitemaps[] = $value;

				continue;
			}

			if ( 'user-agent' === $field ) {
				$token = strtolower( $value );

				if ( strtolower( $userAgent ) === $token ) {
					// A block naming us replaces anything the wildcard
					// block contributed.
					$applies  = true;
					$specific = true;
					$disallow = array();
					$allow    = array();
					$delay    = 0.0;
				} elseif ( '*' === $token && ! $specific ) {
					$applies = true;
				} else {
					$applies = false;
				}

				continue;
			}

			if ( ! $applies ) {
				continue;
			}

			match ( $field ) {
				'disallow'    => $disallow[] = $value,
				'allow'       => $allow[]    = $value,
				'crawl-delay' => $delay      = is_numeric( $value ) ? (float) $value : $delay,
				default       => null,
			};
		}

		return new self( $disallow, $allow, $delay, $sitemaps );
	}

	/**
	 * Whether a path may be fetched.
	 *
	 * @param string $path Path with query string.
	 * @return bool
	 */
	public function allows( string $path ): bool {
		$allowed    = $this->longestMatch( $this->allow, $path );
		$disallowed = $this->longestMatch( $this->disallow, $path );

		if ( null === $disallowed ) {
			return true;
		}

		// The more specific rule wins, which is what every major crawler
		// implements and what a site owner writing "Disallow: /wp-admin/"
		// plus "Allow: /wp-admin/admin-ajax.php" is relying on.
		return null !== $allowed && $allowed >= $disallowed;
	}

	/**
	 * Length of the longest matching prefix, or null when none match.
	 *
	 * @param array<int, string> $rules Rules.
	 * @param string             $path  Path.
	 * @return int|null
	 */
	private function longestMatch( array $rules, string $path ): ?int {
		$best = null;

		foreach ( $rules as $rule ) {
			// An empty Disallow means "nothing is disallowed" and must not
			// be treated as a zero-length prefix matching everything.
			if ( '' === $rule ) {
				continue;
			}

			if ( $this->matches( $rule, $path ) ) {
				$best = max( $best ?? 0, strlen( $rule ) );
			}
		}

		return $best;
	}

	/**
	 * Whether a rule matches a path.
	 *
	 * @param string $rule Rule, which may contain * and end with $.
	 * @param string $path Path.
	 * @return bool
	 */
	private function matches( string $rule, string $path ): bool {
		$anchored = str_ends_with( $rule, '$' );
		$pattern  = $anchored ? substr( $rule, 0, -1 ) : $rule;

		if ( ! str_contains( $pattern, '*' ) ) {
			return $anchored ? $path === $pattern : str_starts_with( $path, $pattern );
		}

		$quoted = array_map(
			// The delimiter has to be passed. preg_quote() without one
			// leaves # unescaped, and # is the delimiter used here.
			static fn ( string $part ): string => preg_quote( $part, '#' ),
			explode( '*', $pattern )
		);

		$regex  = '#^' . implode( '.*', $quoted );
		$regex .= $anchored ? '$#' : '#';

		return 1 === preg_match( $regex, $path );
	}
}
