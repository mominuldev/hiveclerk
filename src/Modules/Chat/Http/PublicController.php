<?php
/**
 * Base for the visitor-facing routes.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Http;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Conversation\Session;
use Hiveclerk\Modules\Chat\Services\SessionService;
use WP_Error;
use WP_REST_Request;

/**
 * Shared gate for every `/public/*` route.
 *
 * These are the only routes in the product an anonymous caller can reach,
 * and they are the ones that spend money, so the gate is not a formality.
 * Two kinds exist:
 *
 * **Session-gated.** The caller presents a token; the callback resolves it
 * to a live session or returns 401. Resolution happens in the permission
 * callback rather than in the handler so that a forged token never reaches
 * code that would query, retrieve or complete. The resolved session is
 * held on the controller for the handler that follows — a controller
 * instance serves one route of one request.
 *
 * **Open, but rate limited.** Bootstrap and session issue cannot require a
 * session because they are how one is obtained. Their gate is a per-IP
 * ceiling, which is a real decision and not `__return_true`: SEC-04's
 * automated check exists to stop a route shipping open, and an
 * unthrottled public POST that writes a database row is open in every
 * sense that matters.
 */
abstract class PublicController extends AbstractController {

	/**
	 * Header carrying the session token.
	 */
	protected const SESSION_HEADER = 'x_hvc_session';

	/**
	 * The session resolved for this request, if any.
	 *
	 * @var Session|null
	 */
	protected ?Session $session = null;

	/**
	 * Construct.
	 *
	 * @param SessionService $sessions Session issue and validation.
	 * @param RateLimiter    $limiter  Rate limiter.
	 */
	public function __construct(
		protected readonly SessionService $sessions,
		protected readonly RateLimiter $limiter
	) {
	}

	/**
	 * A permission callback requiring a valid session token.
	 *
	 * @param int $limit  Requests allowed per window.
	 * @param int $window Window in seconds.
	 * @return callable(WP_REST_Request<array<string, mixed>>): (bool|WP_Error)
	 */
	protected function requiresSession( int $limit, int $window = 60 ): callable {
		return function ( WP_REST_Request $request ) use ( $limit, $window ): bool|WP_Error {
			$token = $request->get_header( self::SESSION_HEADER );

			if ( ! is_string( $token ) || '' === trim( $token ) ) {
				return ApiResponse::error(
					ErrorCode::UNAUTHORIZED,
					'This conversation needs a session. Reload the page to start one.',
					401
				);
			}

			$session = $this->sessions->resolve( trim( $token ) );

			if ( null === $session ) {
				return ApiResponse::error(
					ErrorCode::UNAUTHORIZED,
					'This session has expired. Reload the page to start a new one.',
					401
				);
			}

			// Keyed on session and IP together, per the specification. Either
			// alone is trivially sidestepped: sessions are free to mint, and
			// addresses are cheap to rotate.
			$throttled = $this->throttle(
				$this->limiter,
				'chat|' . $this->sessions->bucketKey( $session ) . '|' . $this->ipKey(),
				$limit,
				$window
			);

			if ( $throttled instanceof WP_Error ) {
				return $throttled;
			}

			$this->session = $session;

			return true;
		};
	}

	/**
	 * A permission callback for routes that issue or describe, not spend.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $limit  Requests allowed per window.
	 * @param int    $window Window in seconds.
	 * @return callable(WP_REST_Request<array<string, mixed>>): (bool|WP_Error)
	 */
	protected function throttledPublic( string $bucket, int $limit, int $window = 60 ): callable {
		return function ( WP_REST_Request $request ) use ( $bucket, $limit, $window ): bool|WP_Error {
			unset( $request );

			$throttled = $this->throttle(
				$this->limiter,
				$bucket . '|' . $this->ipKey(),
				$limit,
				$window
			);

			return $throttled instanceof WP_Error ? $throttled : true;
		};
	}

	/**
	 * The response for a session pointing at something that is gone.
	 *
	 * Deliberately identical to the response for a stale token. A caller
	 * holding a valid session for a deleted conversation and a caller
	 * holding an expired one need the same thing — a new session — and
	 * distinguishing them in the body would only tell an attacker which
	 * conversation ids have been deleted.
	 *
	 * @return WP_Error
	 */
	protected function expired(): WP_Error {
		return ApiResponse::error(
			ErrorCode::UNAUTHORIZED,
			'This session has expired. Reload the page to start a new one.',
			401
		);
	}

	/**
	 * A stable, non-identifying rate-limit key for the caller's address.
	 *
	 * Hashed because it is stored in a cache key and may be printed in a
	 * debug context, and a raw address in either is PII we have no reason
	 * to hold (SEC-12).
	 *
	 * @return string
	 */
	protected function ipKey(): string {
		$remote = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		if ( ! is_string( $remote ) ) {
			return 'unknown';
		}

		$ip = filter_var( wp_unslash( $remote ), FILTER_VALIDATE_IP );

		if ( ! is_string( $ip ) ) {
			return 'unknown';
		}

		$salt = defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '';

		return substr( hash( 'sha256', $salt . '|' . $ip ), 0, 32 );
	}

	/**
	 * The page context a visitor's request carries.
	 *
	 * Every value is sanitised here rather than trusted from the widget.
	 * The widget is our code but it runs on the visitor's machine, which
	 * makes everything it sends an assertion rather than a fact.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<string, mixed>
	 */
	protected function pageContext( WP_REST_Request $request ): array {
		return array(
			'page_url'   => $this->safeUrl( $request->get_param( 'url' ) ),
			'page_title' => $this->safeText( $request->get_param( 'title' ), 200 ),
			'language'   => $this->safeText( $request->get_param( 'language' ), 10 ),
		);
	}

	/**
	 * A URL that belongs to this site, or null.
	 *
	 * Restricted to the site's own host. The value is stored, shown in the
	 * admin and put into a prompt, so an arbitrary URL here is a way to
	 * plant a link in an operator's screen from outside.
	 *
	 * @param mixed $value Raw parameter.
	 * @return string|null
	 */
	protected function safeUrl( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$url = esc_url_raw( trim( $value ) );

		if ( '' === $url ) {
			return null;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		$site = wp_parse_url( get_site_url(), PHP_URL_HOST );

		if ( ! is_string( $host ) || ! is_string( $site ) || strtolower( $host ) !== strtolower( $site ) ) {
			return null;
		}

		return substr( $url, 0, 500 );
	}

	/**
	 * A trimmed, single-line, length-capped string.
	 *
	 * @param mixed $value Raw parameter.
	 * @param int   $limit Maximum characters.
	 * @return string|null
	 */
	protected function safeText( mixed $value, int $limit ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$clean = trim( sanitize_text_field( $value ) );

		if ( '' === $clean ) {
			return null;
		}

		return mb_substr( $clean, 0, $limit );
	}
}
