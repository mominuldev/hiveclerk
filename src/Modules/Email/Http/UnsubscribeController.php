<?php
/**
 * Public unsubscribe endpoint.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Email\Http;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Email\SuppressionReason;
use Hiveclerk\Modules\Email\Services\SuppressionList;
use Hiveclerk\Modules\Email\Services\UnsubscribeTokens;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * One-click unsubscribe (FR-EML-06).
 *
 * ## The permission callback is the token, and it is a real check
 *
 * This is the only public route in the module and it is not
 * `__return_true`. The signed token *is* the credential: it proves this
 * site issued the link, it names the address it applies to, and without a
 * valid one the request is refused before any handler runs. That is what
 * `tools/verify-routes.php` asserts across every route in the plugin, and
 * this one passes on its own terms rather than by exception.
 *
 * ## Why POST and GET both work
 *
 * GET is the link a person clicks. POST with no body is what Gmail and
 * Yahoo send when the recipient presses their own unsubscribe button —
 * `List-Unsubscribe-Post: List-Unsubscribe=One-Click` promises that
 * endpoint exists and answers without a confirmation step. A sender that
 * advertises one-click and then shows a confirmation page fails the
 * bulk-sender requirements it was trying to satisfy.
 *
 * GET returns a page for a human; POST returns JSON for a machine.
 */
final class UnsubscribeController extends AbstractController {

	/**
	 * Attempts allowed per IP per minute.
	 *
	 * The token is unguessable, so this is not protecting the list. It
	 * stops the endpoint being used as a free hashing oracle by somebody
	 * throwing candidate tokens at it.
	 */
	private const RATE_LIMIT = 20;

	/**
	 * Construct.
	 *
	 * @param UnsubscribeTokens $tokens      Token verification.
	 * @param SuppressionList   $suppression Do-not-email list.
	 * @param RateLimiter       $limiter     Rate limiting.
	 */
	public function __construct(
		private readonly UnsubscribeTokens $tokens,
		private readonly SuppressionList $suppression,
		private readonly RateLimiter $limiter
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/public/unsubscribe',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'page' ),
					'permission_callback' => array( $this, 'hasValidToken' ),
					'args'                => $this->args(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'oneClick' ),
					'permission_callback' => array( $this, 'hasValidToken' ),
					'args'                => $this->args(),
				),
			)
		);
	}

	/**
	 * Refuse anything without a token this site issued.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return bool|WP_Error
	 */
	public function hasValidToken( WP_REST_Request $request ): bool|WP_Error {
		$throttle = $this->throttle(
			$this->limiter,
			'unsubscribe:' . $this->clientKey(),
			self::RATE_LIMIT
		);

		if ( $throttle instanceof WP_Error ) {
			return $throttle;
		}

		$token = (string) $request->get_param( 'token' );

		if ( null === $this->tokens->verify( $token ) ) {
			return ApiResponse::error(
				ErrorCode::FORBIDDEN,
				__( 'That unsubscribe link is not valid. Reply to the email and we will remove you by hand.', 'hiveclerk' ),
				403
			);
		}

		return true;
	}

	/**
	 * A human clicked the link.
	 *
	 * ## Why this hijacks the serializer
	 *
	 * The REST server JSON-encodes whatever a handler returns, and a
	 * recipient who clicks unsubscribe must not be shown `{"data":...}`.
	 * `rest_pre_serve_request` is the only supported way to emit a body
	 * the REST server did not encode. The filter is added here rather
	 * than at boot and removes itself after firing, so it can only ever
	 * affect the one request that reached this handler.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function page( WP_REST_Request $request ): WP_REST_Response {
		$this->apply( $request );

		$html = $this->html();

		add_filter(
			'rest_pre_serve_request',
			static function ( bool $served ) use ( $html ): bool {
				if ( $served ) {
					return $served;
				}

				header( 'Content-Type: text/html; charset=utf-8' );
				// Nothing here is worth caching, and a cached "you are
				// unsubscribed" page served to the next visitor would be
				// alarming.
				header( 'Cache-Control: no-store' );

				// Already escaped in html(), field by field.
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

				return true;
			}
		);

		return new WP_REST_Response( null, 200 );
	}

	/**
	 * A mail client pressed its own button.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function oneClick( WP_REST_Request $request ): WP_REST_Response {
		$this->apply( $request );

		// Always 200 with the same body, whether the address was already
		// suppressed or not. A response that differed would tell an
		// unauthenticated caller whether a given hash is on the list.
		return ApiResponse::ok( array( 'unsubscribed' => true ) );
	}

	/**
	 * The page a human sees.
	 *
	 * Rendered here rather than through a template because a REST route
	 * has no theme loaded around it, and pulling one in would run every
	 * plugin's front-end hooks on an endpoint that must stay fast and
	 * predictable.
	 *
	 * @return string
	 */
	public function html(): string {
		return sprintf(
			'<!doctype html><html lang="%1$s"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex">'
			. '<title>%2$s</title></head>'
			. '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem;line-height:1.6">'
			. '<h1 style="font-size:1.25rem">%2$s</h1><p>%3$s</p><p><a href="%4$s">%5$s</a></p></body></html>',
			esc_attr( get_bloginfo( 'language' ) ),
			esc_html__( 'You have been unsubscribed', 'hiveclerk' ),
			esc_html__( 'We will not send you any more follow-up email from this site. Nothing else changes, and you can still get in touch whenever you want to.', 'hiveclerk' ),
			esc_url( home_url() ),
			esc_html( get_bloginfo( 'name' ) )
		);
	}

	/**
	 * Suppress the address the token names.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return void
	 */
	private function apply( WP_REST_Request $request ): void {
		$hash = $this->tokens->verify( (string) $request->get_param( 'token' ) );

		if ( null !== $hash ) {
			$this->suppression->suppressHash( $hash, SuppressionReason::Unsubscribed );
		}
	}

	/**
	 * A per-caller key for the rate limiter.
	 *
	 * Hashed, because this is the one endpoint in the plugin an
	 * unauthenticated stranger reaches and their address is not something
	 * worth keeping in a rate-limit table in the clear.
	 *
	 * @return string
	 */
	private function clientKey(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown';

		return substr( hash( 'sha256', $ip ), 0, 32 );
	}

	/**
	 * Arguments both methods accept.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function args(): array {
		return array(
			'token' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
