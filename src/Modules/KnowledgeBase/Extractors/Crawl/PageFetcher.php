<?php
/**
 * Fetching pages for the crawler.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors\Crawl;

use WP_Error;

/**
 * Retrieves one URL, safely.
 *
 * ## Why wp_safe_remote_get and not wp_remote_get
 *
 * A crawl source takes a URL from an admin form and asks the *server* to
 * fetch it. That is a server-side request forgery primitive: point it at
 * `http://169.254.169.254/` on a cloud host and the response is the
 * instance's credentials, fetched by our code, stored in the customer's
 * database, and readable through the knowledge browser.
 *
 * `wp_safe_remote_get()` runs the URL through `wp_http_validate_url()`,
 * which rejects loopback and private address ranges and non-standard
 * ports. It is a meaningful control rather than a complete one — DNS
 * rebinding defeats a check made before the socket opens — but it is the
 * one WordPress provides, it is what core uses for the same class of
 * request, and the alternative is writing a worse one.
 *
 * The visible cost is that a site cannot crawl `localhost` or a private
 * staging host. That is the control working.
 */
final class PageFetcher {

	/**
	 * Largest body worth reading, in bytes.
	 */
	public const MAX_BYTES = 5242880;

	/**
	 * Seconds before giving up on a request.
	 */
	private const TIMEOUT = 15;

	/**
	 * Redirects followed before giving up.
	 */
	private const REDIRECTS = 3;

	/**
	 * Content types worth parsing.
	 */
	private const HTML_TYPES = array( 'text/html', 'application/xhtml+xml' );

	/**
	 * Fetch a URL.
	 *
	 * @param string $url       Absolute URL.
	 * @param string $userAgent User agent to send.
	 * @return FetchResult
	 */
	public function get( string $url, string $userAgent ): FetchResult {
		if ( $this->isBlocked( $url ) ) {
			return FetchResult::failed( $url, 'That address is not reachable from a crawl.' );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => self::REDIRECTS,
				'user-agent'          => $userAgent,
				'limit_response_size' => self::MAX_BYTES,
				'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml' ),
			)
		);

		if ( $response instanceof WP_Error ) {
			return FetchResult::failed( $url, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			return FetchResult::failed( $url, sprintf( 'HTTP %d', $status ), $status );
		}

		// A header can legitimately appear more than once, in which case
		// WordPress hands back an array. Casting that to a string is a
		// notice and an empty type, which this method would then treat as
		// "probably HTML".
		$header = wp_remote_retrieve_header( $response, 'content-type' );
		$type   = strtolower( is_array( $header ) ? (string) ( $header[0] ?? '' ) : (string) $header );

		if ( ! $this->isHtml( $type ) ) {
			// A PDF or an image reached through a link. Not an error, and
			// not something this extractor can read — the PDF source type
			// exists for that and does a better job.
			return FetchResult::skipped( $url, sprintf( 'Not HTML (%s)', '' === $type ? 'unknown type' : $type ) );
		}

		return FetchResult::ok(
			$url,
			(string) wp_remote_retrieve_body( $response ),
			$status
		);
	}

	/**
	 * Fetch something that is not expected to be HTML.
	 *
	 * Used for robots.txt and sitemaps, which are text and XML.
	 *
	 * @param string $url       Absolute URL.
	 * @param string $userAgent User agent to send.
	 * @return string|null Body, or null when it could not be read.
	 */
	public function getRaw( string $url, string $userAgent ): ?string {
		if ( $this->isBlocked( $url ) ) {
			return null;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => self::REDIRECTS,
				'user-agent'          => $userAgent,
				'limit_response_size' => self::MAX_BYTES,
			)
		);

		if ( $response instanceof WP_Error ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			return null;
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Whether a URL resolves somewhere a crawl must not go.
	 *
	 * `wp_safe_remote_get()` alone is not enough. `wp_http_validate_url()`
	 * rejects loopback and the RFC 1918 private ranges, but **not
	 * link-local — 169.254.0.0/16**. That range holds the cloud instance
	 * metadata endpoint at 169.254.169.254, which on AWS, GCP and Azure
	 * serves the machine's own credentials to anything that asks. A crawl
	 * source pointed at it would have fetched those credentials with the
	 * server's own network access, chunked them, and stored them in the
	 * customer's database where the knowledge browser displays them.
	 *
	 * Measured before this check existed: 127.0.0.1, 10.0.0.5 and
	 * 192.168.1.1 were all refused outright, and 169.254.169.254 was
	 * allowed through to a real connection attempt.
	 *
	 * FILTER_FLAG_NO_RES_RANGE covers link-local along with 0.0.0.0/8,
	 * the reserved 240.0.0.0/4 block, and the IPv6 equivalents that
	 * WordPress's own check does not look at either.
	 *
	 * This is a pre-flight check and therefore beatable by DNS rebinding —
	 * a name that resolves to a public address here and a private one when
	 * the socket opens. Closing that needs resolution and connection to
	 * happen together, which the WordPress HTTP API does not expose. The
	 * gap is narrow, it is documented, and it is far smaller than the one
	 * that existed without this.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private function isBlocked( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return true;
		}

		// An IPv6 literal arrives wrapped in brackets.
		$host = trim( $host, '[]' );

		foreach ( $this->addressesFor( $host ) as $address ) {
			$public = filter_var(
				$address,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);

			if ( false === $public ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every address a host resolves to.
	 *
	 * All of them, not just the first: a name with several A records is
	 * only safe if every one of them is, and a resolver is free to return
	 * them in any order.
	 *
	 * @param string $host Host name or IP literal.
	 * @return array<int, string>
	 */
	private function addressesFor( string $host ): array {
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$addresses = array();

		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( isset( $record['ip'] ) && is_string( $record['ip'] ) ) {
					$addresses[] = $record['ip'];
				}

				if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
					$addresses[] = $record['ipv6'];
				}
			}
		}

		if ( array() !== $addresses ) {
			return $addresses;
		}

		// dns_get_record can fail where the system resolver succeeds.
		// Falling back keeps ordinary hosts crawlable; a name that
		// resolves to nothing at all is left to the HTTP layer to refuse.
		$resolved = gethostbyname( $host );

		return $resolved === $host ? array() : array( $resolved );
	}

	/**
	 * Whether a content type is markup we can read.
	 *
	 * @param string $contentType Header value.
	 * @return bool
	 */
	private function isHtml( string $contentType ): bool {
		foreach ( self::HTML_TYPES as $type ) {
			if ( str_contains( $contentType, $type ) ) {
				return true;
			}
		}

		// An empty Content-Type is treated as HTML. Misconfigured servers
		// omit it, and refusing those would silently skip whole sites.
		return '' === trim( $contentType );
	}
}
