<?php
/**
 * Fetching pages for the crawler.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors\Crawl;

use Hiveclerk\Infrastructure\Http\OutboundUrlGuard;
use Hiveclerk\Infrastructure\Http\SafeRedirectFollower;
use WP_Error;

/**
 * Retrieves one URL, safely.
 *
 * ## Why the guard, and why on every hop
 *
 * A crawl source takes a URL from an admin form and asks the *server* to
 * fetch it. That is a server-side request forgery primitive: point it at
 * `http://169.254.169.254/` on a cloud host and the response is the
 * instance's credentials, fetched by our code, stored in the customer's
 * database, and readable through the knowledge browser.
 *
 * `wp_safe_remote_get()` runs the URL through `wp_http_validate_url()`,
 * which rejects loopback and private address ranges and non-standard
 * ports — but not link-local, which is exactly where the metadata endpoint
 * lives. {@see OutboundUrlGuard} covers that gap, and used to cover it only
 * for the first URL: WordPress followed redirects itself, checking each hop
 * with its own weaker rules, so a public URL that redirected to the metadata
 * address was fetched and indexed. Every request now goes through
 * {@see SafeRedirectFollower}, which walks the chain a hop at a time and
 * asks the guard about each one.
 *
 * Both remain pre-flight checks and are therefore beatable by DNS rebinding
 * — a name that resolves publicly here and privately when the socket opens.
 * Closing that needs resolution and connection in one step, which the
 * WordPress HTTP API does not expose.
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
	 * Content types worth parsing.
	 */
	private const HTML_TYPES = array( 'text/html', 'application/xhtml+xml' );

	/**
	 * Construct.
	 *
	 * @param OutboundUrlGuard     $guard    Private-network check.
	 * @param SafeRedirectFollower $follower Per-hop redirect follower.
	 */
	public function __construct(
		private readonly OutboundUrlGuard $guard = new OutboundUrlGuard(),
		private readonly SafeRedirectFollower $follower = new SafeRedirectFollower()
	) {
	}

	/**
	 * Fetch a URL.
	 *
	 * @param string $url       Absolute URL.
	 * @param string $userAgent User agent to send.
	 * @return FetchResult
	 */
	public function get( string $url, string $userAgent ): FetchResult {
		if ( $this->guard->isBlocked( $url ) ) {
			return FetchResult::failed( $url, 'That address is not reachable from a crawl.' );
		}

		$response = $this->follower->request(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
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
		if ( $this->guard->isBlocked( $url ) ) {
			return null;
		}

		$response = $this->follower->request(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
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
