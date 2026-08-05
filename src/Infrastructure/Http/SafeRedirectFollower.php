<?php
/**
 * Redirect following that re-checks every hop.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Infrastructure\Http;

use WP_Error;

/**
 * Follows redirects one hop at a time, guarding each one (SEC-06).
 *
 * ## The hole this closes
 *
 * {@see OutboundUrlGuard} is a pre-flight check: it runs against the URL a
 * customer typed and then never again. `wp_safe_remote_get()` follows up to
 * five redirects internally, and each hop is re-validated by WordPress's own
 * `wp_http_validate_url()` — which blocks loopback and RFC 1918 but **not**
 * link-local. So the address the guard exists for, `169.254.169.254`, is
 * unreachable directly and reachable through a redirect:
 *
 *     crawl source → https://attacker.example/    (public, guard says fine)
 *                  → 302 Location: http://169.254.169.254/latest/meta-data/…
 *                  → WordPress follows it, body is indexed as knowledge
 *
 * Measured, not assumed: `wp_http_validate_url()` returns the URL unchanged
 * for the metadata address on this install, while `OutboundUrlGuard` blocks
 * it. That difference is the whole vulnerability, and D15 §11 SEC-06 names
 * the control that closes it — "re-validate after every redirect".
 *
 * ## Why the following is done here rather than by WordPress
 *
 * There is no filter on the per-hop check. `http_request_host_is_external`
 * only widens what core permits, never narrows it, and hooking
 * `pre_http_request` would apply our rules to every other plugin's requests
 * on the site. So redirection is switched off at the HTTP layer and the
 * chain is walked here, where the guard can be asked about each hop before
 * the socket opens.
 */
final class SafeRedirectFollower {

	/**
	 * How many hops to follow before giving up.
	 *
	 * The same ceiling `wp_safe_remote_get()` uses by default, so switching
	 * to this does not change which ordinary sites are reachable.
	 */
	private const MAX_HOPS = 5;

	/**
	 * Construct.
	 *
	 * @param OutboundUrlGuard $guard Private-network check.
	 */
	public function __construct(
		private readonly OutboundUrlGuard $guard = new OutboundUrlGuard()
	) {
	}

	/**
	 * Perform a request, following redirects with the guard on every hop.
	 *
	 * @param string               $url    Absolute URL.
	 * @param array<string, mixed> $args   Arguments for the WordPress HTTP API.
	 * @param string               $method HTTP method.
	 * @return array<string, mixed>|WP_Error Response, or an error.
	 */
	public function request( string $url, array $args, string $method = 'GET' ) {
		$args['redirection'] = 0;
		$args['method']      = $method;

		for ( $hop = 0; $hop <= self::MAX_HOPS; $hop++ ) {
			if ( $this->guard->isBlocked( $url ) ) {
				return new WP_Error( 'hiveclerk_blocked_url', __( 'That address is not reachable from this site.', 'hiveclerk' ) );
			}

			$response = wp_safe_remote_request( $url, $args );

			if ( $response instanceof WP_Error ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( $code < 300 || $code > 399 ) {
				return $response;
			}

			$location = wp_remote_retrieve_header( $response, 'location' );
			$location = is_array( $location ) ? (string) ( $location[0] ?? '' ) : (string) $location;

			if ( '' === $location ) {
				// A 3xx with nowhere to go. Handed back as-is rather than
				// treated as an error: the caller's status handling already
				// refuses anything outside 2xx.
				return $response;
			}

			$url = $this->resolve( $url, $location );

			if ( '' === $url ) {
				return new WP_Error( 'hiveclerk_bad_redirect', __( 'That site redirected somewhere this crawl cannot follow.', 'hiveclerk' ) );
			}

			/*
			 * A redirect that changes method. WordPress's own handler turns
			 * everything except 307/308 into a GET with no body, and copying
			 * that matters here: a POST body replayed onto an attacker's
			 * second hop is the lead data leaving the building.
			 */
			if ( 307 !== $code && 308 !== $code && 'GET' !== $args['method'] ) {
				$args['method'] = 'GET';
				unset( $args['body'] );
			}
		}

		return new WP_Error( 'hiveclerk_too_many_redirects', __( 'That address redirected too many times.', 'hiveclerk' ) );
	}

	/**
	 * Turn a Location header into an absolute URL we are willing to fetch.
	 *
	 * Returns an empty string for anything that is not plain HTTP or HTTPS.
	 * A redirect to `file://`, `gopher://` or `data:` is not a page to crawl,
	 * and each of them is a way to make a fetcher read something it was never
	 * pointed at.
	 *
	 * @param string $from     URL the redirect came from.
	 * @param string $location Raw Location header.
	 * @return string Absolute URL, or an empty string.
	 */
	private function resolve( string $from, string $location ): string {
		$parts = wp_parse_url( $location );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		// Relative Location headers are legal and common.
		if ( ! isset( $parts['scheme'] ) ) {
			$location = (string) \WP_Http::make_absolute_url( $location, $from );
			$parts    = wp_parse_url( $location );

			if ( ! is_array( $parts ) ) {
				return '';
			}
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		return $location;
	}
}
