<?php
/**
 * Path glob matching.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead\Scoring;

/**
 * The same glob dialect the display rules use.
 *
 * Deliberately the same: an operator who has written `/products/*` once
 * on the Display tab should not discover that the Scoring tab wants a
 * regular expression. `*` is the only metacharacter and everything else
 * is quoted, so a customer-supplied pattern cannot backtrack.
 *
 * @see \Hiveclerk\Domain\Agent\DisplayRules
 */
final class PathPattern {

	/**
	 * Whether a path matches a pattern.
	 *
	 * @param string $pattern Configured pattern.
	 * @param string $path    Path or full URL.
	 * @return bool
	 */
	public static function matches( string $pattern, string $path ): bool {
		$expression = '#^' . str_replace( '\*', '.*', preg_quote( self::normalise( $pattern ), '#' ) ) . '$#i';

		return 1 === preg_match( $expression, self::normalise( $path ) );
	}

	/**
	 * Reduce a URL or pattern to a comparable path.
	 *
	 * A pasted address from the browser bar is the expected way this
	 * field gets filled in, so a full URL is accepted and reduced.
	 *
	 * @param string $value Path or full URL.
	 * @return string
	 */
	public static function normalise( string $value ): string {
		$path = trim( $value );

		if ( 1 === preg_match( '#^https?://[^/]+(/.*)?$#i', $path, $match ) ) {
			$path = $match[1] ?? '/';
		}

		$query = strpos( $path, '?' );

		if ( false !== $query ) {
			$path = substr( $path, 0, $query );
		}

		$fragment = strpos( $path, '#' );

		if ( false !== $fragment ) {
			$path = substr( $path, 0, $fragment );
		}

		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		return '/' === $path ? '/' : rtrim( $path, '/' );
	}
}
