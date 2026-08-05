<?php
/**
 * Widget bootstrap and session issue.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Http;

use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Chat\Services\SessionService;
use Hiveclerk\Modules\Chat\Services\WidgetConfig;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * How a visitor's browser learns there is a clerk here, and gets a token.
 *
 * Bootstrap answers 204 rather than an empty object when no clerk serves
 * this page. That is a product decision as much as an HTTP one: the
 * wireframes are explicit that a page with nobody on duty shows no
 * launcher at all, and a 200 carrying `agent: null` invites a widget to
 * render an empty bubble while it works out what to do.
 */
final class BootstrapController extends PublicController {

	/**
	 * Bootstrap requests allowed per minute, per address.
	 *
	 * Generous: the response is cacheable and cheap, and a visitor moving
	 * quickly through a catalogue issues one per page view.
	 */
	private const BOOTSTRAP_LIMIT = 60;

	/**
	 * Session issues allowed per minute, per address.
	 *
	 * Each one writes two rows. The specification's figure, kept.
	 *
	 * @see docs/09-api-specification.md §1.6
	 */
	private const SESSION_LIMIT = 10;

	/**
	 * Construct.
	 *
	 * @param SessionService $sessions Session issue and validation.
	 * @param RateLimiter    $limiter  Rate limiter.
	 * @param WidgetConfig   $config   Clerk selection and widget payload.
	 */
	public function __construct(
		SessionService $sessions,
		RateLimiter $limiter,
		private readonly WidgetConfig $config
	) {
		parent::__construct( $sessions, $limiter );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/public/bootstrap',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'bootstrap' ),
				'permission_callback' => $this->throttledPublic( 'bootstrap', self::BOOTSTRAP_LIMIT ),
				'args'                => array(
					'agent' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'url'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/public/session',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'issue' ),
				'permission_callback' => $this->throttledPublic( 'session', self::SESSION_LIMIT ),
				'args'                => array(
					'agent'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'url'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
					),
					'title'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'language' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Describe the clerk serving this page.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function bootstrap( WP_REST_Request $request ): WP_REST_Response {
		$agent = $this->config->select( $this->uuidParam( $request, 'agent' ) );

		if ( null === $agent ) {
			return ApiResponse::noContent();
		}

		return ApiResponse::ok(
			$this->config->payload( $agent ),
			array(),
			200,
			// Five minutes, per the specification. The payload holds no
			// visitor data, which is what makes a shared cache safe here and
			// not on any other public route.
			array( 'Cache-Control' => 'public, max-age=300' )
		);
	}

	/**
	 * Open a conversation and hand back its token.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function issue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$agent = $this->config->select( $this->uuidParam( $request, 'agent' ) );

		if ( null === $agent ) {
			return ApiResponse::error(
				ErrorCode::NOT_FOUND,
				'Nobody is on duty here right now.',
				404
			);
		}

		$issued = $this->sessions->issue( $agent, $this->pageContext( $request ) );

		return ApiResponse::ok(
			array(
				'session'      => $issued['token'],
				'conversation' => $issued['conversation']->uuid->value,
				'expires_at'   => null === $issued['session']->expiresAt
					? null
					: $issued['session']->expiresAt->format( DATE_ATOM ),
				'greeting'     => $agent->greeting,
			),
			array(),
			201
		);
	}

	/**
	 * Read a parameter that must be a UUID if it is present at all.
	 *
	 * A malformed value is treated as absent rather than as an error. The
	 * widget passes through whatever the page's shortcode gave it, and a
	 * typo in an attribute should fall back to the default clerk rather
	 * than break the widget on that page.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @param string                                $key     Parameter name.
	 * @return Uuid|null
	 */
	private function uuidParam( WP_REST_Request $request, string $key ): ?Uuid {
		$value = $request->get_param( $key );

		if ( ! is_string( $value ) || ! Uuid::isValid( $value ) ) {
			return null;
		}

		return new Uuid( $value );
	}
}
