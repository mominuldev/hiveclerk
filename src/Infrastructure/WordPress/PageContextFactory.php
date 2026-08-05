<?php
/**
 * Describing the current page for the display rules.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Infrastructure\WordPress;

use Hiveclerk\Domain\Agent\PageContext;

/**
 * Builds a PageContext from WordPress and from the request.
 *
 * Everything WordPress-shaped about display rules lives here so the rules
 * themselves stay pure and testable. It is also the boundary where the
 * difference between what the server knows and what the browser claims is
 * decided, and the two are not the same kind of fact:
 *
 * - **Signed in, and which roles** — the server knows. Taken from the
 *   cookie, never from a parameter, because it is the one input a visitor
 *   would have a motive to lie about.
 * - **Which page** — the browser is the only one who knows, once a page
 *   is served from a full-page cache. It is accepted, but only as a path
 *   on this site.
 * - **Device and country** — inferred from headers, which are hints. Both
 *   fail open in `DisplayRules` rather than hiding the clerk.
 */
final class PageContextFactory {

	/**
	 * Headers a CDN may use to report the visitor's country.
	 *
	 * Cloudflare first because it is the one most of our customers are
	 * behind without knowing it.
	 */
	private const COUNTRY_HEADERS = array(
		'HTTP_CF_IPCOUNTRY',
		'HTTP_X_COUNTRY_CODE',
		'HTTP_X_GEOIP_COUNTRY',
		'GEOIP_COUNTRY_CODE',
	);

	/**
	 * Describe the current request.
	 *
	 * @param string|null $url Page the widget says it is on, when it says so.
	 * @return PageContext
	 */
	public function current( ?string $url = null ): PageContext {
		$path = null !== $url ? $this->pathFromUrl( $url ) : $this->requestPath();

		return new PageContext(
			path: $path,
			device: $this->device(),
			isLoggedIn: is_user_logged_in(),
			roles: $this->roles(),
			country: $this->country(),
			url: $url,
		);
	}

	/**
	 * The path of the current request.
	 *
	 * @return string
	 */
	private function requestPath(): string {
		$uri = $_SERVER['REQUEST_URI'] ?? '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		if ( ! is_string( $uri ) ) {
			return '/';
		}

		$path = wp_parse_url( esc_url_raw( wp_unslash( $uri ) ), PHP_URL_PATH );

		return is_string( $path ) && '' !== $path ? $path : '/';
	}

	/**
	 * The path of a URL, if it belongs to this site.
	 *
	 * A URL on another host is not a page of this site, and treating one
	 * as such would let a caller claim to be anywhere in order to satisfy
	 * an include rule.
	 *
	 * @param string $url Candidate URL.
	 * @return string
	 */
	private function pathFromUrl( string $url ): string {
		$clean = esc_url_raw( trim( $url ) );

		if ( '' === $clean ) {
			return '/';
		}

		$host = wp_parse_url( $clean, PHP_URL_HOST );
		$site = wp_parse_url( get_site_url(), PHP_URL_HOST );

		if ( is_string( $host ) && is_string( $site ) && strtolower( $host ) !== strtolower( $site ) ) {
			return '/';
		}

		$path = wp_parse_url( $clean, PHP_URL_PATH );

		return is_string( $path ) && '' !== $path ? $path : '/';
	}

	/**
	 * Roles the signed-in user holds, lower-cased.
	 *
	 * @return array<int, string>
	 */
	private function roles(): array {
		if ( ! is_user_logged_in() ) {
			return array();
		}

		$user = wp_get_current_user();

		return array_values(
			array_map(
				static fn ( $role ): string => strtolower( (string) $role ),
				(array) $user->roles
			)
		);
	}

	/**
	 * Device class, as far as a user agent can say.
	 *
	 * `wp_is_mobile()` reports tablets as mobile, which is wrong for the
	 * one rule an operator writes about tablets — "not on phones" usually
	 * means the small screen, not the iPad. The tablet test runs first for
	 * that reason.
	 *
	 * @return string
	 */
	private function device(): string {
		$agent = $_SERVER['HTTP_USER_AGENT'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		$agent = is_string( $agent ) ? sanitize_text_field( wp_unslash( $agent ) ) : '';

		if ( 1 === preg_match( '/iPad|Tablet|PlayBook|Silk|Kindle|Nexus 7|Nexus 10/i', $agent ) ) {
			return 'tablet';
		}

		if ( 1 === preg_match( '/Android/i', $agent ) && 1 !== preg_match( '/Mobile/i', $agent ) ) {
			return 'tablet';
		}

		return wp_is_mobile() ? 'mobile' : 'desktop';
	}

	/**
	 * The visitor's country, when something upstream reports one.
	 *
	 * @return string|null
	 */
	private function country(): ?string {
		foreach ( self::COUNTRY_HEADERS as $header ) {
			$value = $_SERVER[ $header ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

			if ( ! is_string( $value ) ) {
				continue;
			}

			$code = strtoupper( sanitize_text_field( wp_unslash( $value ) ) );

			// "XX" is what Cloudflare sends for a client it could not
			// place, and "T1" is what it sends for Tor. Neither is a
			// country, and matching either against a rule would be a
			// coincidence rather than a decision.
			if ( 1 === preg_match( '/^[A-Z]{2}$/', $code ) && ! in_array( $code, array( 'XX', 'T1' ), true ) ) {
				return $code;
			}
		}

		return null;
	}
}
