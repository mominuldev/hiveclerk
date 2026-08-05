<?php
/**
 * URL normalisation for crawling.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors\Crawl;

/**
 * Decides whether two URLs are the same page.
 *
 * Almost every wasted request in a crawl comes from here. A site links
 * to `/about`, `/about/`, `/about?utm_source=nav` and
 * `/about#team` — four strings, one page. Without normalisation the
 * crawler fetches it four times, indexes it four times, and the clerk
 * cites whichever copy retrieval happened to rank first.
 */
final class UrlNormaliser {

	/**
	 * Query parameters dropped before comparing.
	 *
	 * Tracking parameters do not change what a page says. Keeping them
	 * turns one page into as many copies as there are campaigns pointing
	 * at it.
	 */
	private const NOISE = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'utm_id',
		'gclid',
		'fbclid',
		'msclkid',
		'mc_cid',
		'mc_eid',
		'ref',
		'replytocom',
	);

	/**
	 * Reduce a URL to a canonical form.
	 *
	 * @param string $url Absolute URL.
	 * @return string|null Canonical URL, or null when unusable.
	 */
	public function canonical( string $url ): ?string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );

		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}

		$host = strtolower( (string) $parts['host'] );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;

		// A default port written explicitly is the same address as one
		// left out.
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = null;
		}

		$path = (string) ( $parts['path'] ?? '/' );
		$path = '' === $path ? '/' : $path;

		// A trailing slash is removed everywhere except the root, where it
		// is the whole path.
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
			$path = '' === $path ? '/' : $path;
		}

		$query = $this->query( (string) ( $parts['query'] ?? '' ) );

		// The fragment is dropped entirely. It is never sent to the
		// server, so two URLs differing only by fragment are one request.
		return $scheme . '://' . $host
			. ( null === $port ? '' : ':' . $port )
			. $path
			. ( '' === $query ? '' : '?' . $query );
	}

	/**
	 * Whether two URLs share a host.
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 * @return bool
	 */
	public function sameHost( string $a, string $b ): bool {
		$hostA = $this->host( $a );
		$hostB = $this->host( $b );

		if ( null === $hostA || null === $hostB ) {
			return false;
		}

		// www is treated as the same site. Almost every site serves both
		// and links between them inconsistently.
		return $this->withoutWww( $hostA ) === $this->withoutWww( $hostB );
	}

	/**
	 * Strip a leading www. label.
	 *
	 * Not ltrim( $host, 'www.' ), which takes a character list rather than
	 * a prefix: it would turn "web.example.com" into "eb.example.com" and
	 * decide that two unrelated hosts were the same site.
	 *
	 * @param string $host Host name.
	 * @return string
	 */
	private function withoutWww( string $host ): string {
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * The host of a URL.
	 *
	 * @param string $url URL.
	 * @return string|null
	 */
	public function host( string $url ): ?string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : null;
	}

	/**
	 * Resolve a link against the page it appeared on.
	 *
	 * @param string $link Possibly relative link.
	 * @param string $base Absolute URL of the containing page.
	 * @return string|null
	 */
	public function resolve( string $link, string $base ): ?string {
		$link = trim( $link );

		if ( '' === $link || str_starts_with( $link, '#' ) ) {
			return null;
		}

		// Schemes that are not pages. mailto: and tel: are common in
		// footers; javascript: and data: are worth refusing explicitly.
		foreach ( array( 'mailto:', 'tel:', 'javascript:', 'data:', 'sms:' ) as $scheme ) {
			if ( str_starts_with( strtolower( $link ), $scheme ) ) {
				return null;
			}
		}

		$resolved = $this->join( $link, $base );

		return null === $resolved ? null : $this->canonical( $resolved );
	}

	/**
	 * Turn a relative reference into an absolute URL.
	 *
	 * @param string $link Link.
	 * @param string $base Base URL.
	 * @return string|null
	 */
	private function join( string $link, string $base ): ?string {
		if ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*://#i', $link ) ) {
			return $link;
		}

		$parts = wp_parse_url( $base );

		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return null;
		}

		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host']
			. ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( str_starts_with( $link, '//' ) ) {
			return ( $parts['scheme'] ?? 'https' ) . ':' . $link;
		}

		if ( str_starts_with( $link, '/' ) ) {
			return $origin . $link;
		}

		$directory = rtrim( dirname( (string) ( $parts['path'] ?? '/' ) ), '/' );

		return $origin . $directory . '/' . $link;
	}

	/**
	 * Sort and filter a query string.
	 *
	 * @param string $query Raw query string.
	 * @return string
	 */
	private function query( string $query ): string {
		if ( '' === $query ) {
			return '';
		}

		parse_str( $query, $params );

		foreach ( self::NOISE as $noise ) {
			unset( $params[ $noise ] );
		}

		if ( array() === $params ) {
			return '';
		}

		// Sorted, so ?a=1&b=2 and ?b=2&a=1 are recognised as one page.
		ksort( $params );

		return http_build_query( $params );
	}
}
