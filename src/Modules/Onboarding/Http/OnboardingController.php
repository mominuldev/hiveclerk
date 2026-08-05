<?php
/**
 * Onboarding endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Onboarding\Http;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Modules\Onboarding\Services\OnboardingState;
use Hiveclerk\Modules\Onboarding\Services\SourceDetector;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The five-step wizard (D9 §3.7, FR-ONB-01, 04, 05).
 *
 * `manage_settings` throughout, and that is stricter than it looks: step
 * one stores a provider API key, and every later step is a decision about
 * money the site will spend. A shop manager who can answer conversations
 * has no business choosing which model the site is billed for.
 *
 * All five steps are individually resumable, and the state endpoint is
 * what the SPA reads on boot to decide whether to show the wizard at all.
 */
final class OnboardingController extends AbstractController {

	/**
	 * Detections allowed per minute.
	 *
	 * Detection samples posts on every call. Cheap, but not free, and an
	 * unthrottled endpoint is a way for one authenticated user to make a
	 * customer's database do twenty `get_posts()` calls per request.
	 */
	private const DETECT_LIMIT = 20;

	/**
	 * Construct.
	 *
	 * @param OnboardingState $state    Wizard progress.
	 * @param SourceDetector  $detector Source detection.
	 * @param RateLimiter     $limiter  Rate limiting.
	 */
	public function __construct(
		private readonly OnboardingState $state,
		private readonly SourceDetector $detector,
		private readonly RateLimiter $limiter
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		$capability = $this->requires( Capabilities::MANAGE_SETTINGS );

		register_rest_route(
			self::NAMESPACE,
			'/admin/onboarding/state',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => $capability,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/onboarding/step/(?P<step>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'step' ),
				'permission_callback' => $capability,
				'args'                => array(
					'step'    => array(
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'maximum'           => OnboardingState::STEPS,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'agent'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'sources' => array(
						'type'     => 'array',
						'required' => false,
						'items'    => array( 'type' => 'string' ),
					),
					'choice'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/onboarding/detect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'detect' ),
				'permission_callback' => $capability,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/onboarding/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'complete' ),
				'permission_callback' => $capability,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/onboarding/skip',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'skip' ),
				'permission_callback' => $capability,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/onboarding/restart',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'restart' ),
				'permission_callback' => $capability,
			)
		);
	}

	/**
	 * Where the operator got to.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return ApiResponse::ok( $this->state->current() );
	}

	/**
	 * Record a finished step.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function step( WP_REST_Request $request ): WP_REST_Response {
		$payload = array();

		foreach ( array( 'agent', 'choice' ) as $field ) {
			$value = $request->get_param( $field );

			if ( is_string( $value ) && '' !== $value ) {
				$payload[ $field ] = $value;
			}
		}

		$sources = $request->get_param( 'sources' );

		if ( is_array( $sources ) ) {
			// Re-cleaned here as well as at the route. These land in an
			// option that is read back by code building links to sources,
			// and `items` in a route schema is advisory unless a
			// validate_callback runs — the same trap Sprint 7 found on
			// /public/events.
			$payload['sources'] = array_values(
				array_map(
					static fn ( $value ): string => sanitize_text_field( (string) $value ),
					array_filter( $sources, 'is_string' )
				)
			);
		}

		return ApiResponse::ok(
			$this->state->completeStep( (int) $request->get_param( 'step' ), $payload )
		);
	}

	/**
	 * What is worth indexing on this site (FR-ONB-04).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function detect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );

		$throttled = $this->throttle(
			$this->limiter,
			'onboarding-detect:' . get_current_user_id(),
			self::DETECT_LIMIT
		);

		if ( $throttled instanceof WP_Error ) {
			return $throttled;
		}

		return ApiResponse::ok( $this->detector->detect() );
	}

	/**
	 * Finish the wizard.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function complete( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$state = $this->state->complete();

		/**
		 * Fires when setup is finished.
		 *
		 * @param array<string, mixed> $state Final wizard state.
		 */
		do_action( 'hiveclerk/onboarding/completed', $state );

		return ApiResponse::ok( $state );
	}

	/**
	 * Leave the wizard without finishing it (FR-ONB-05).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function skip( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return ApiResponse::ok( $this->state->skip() );
	}

	/**
	 * Run setup again from the beginning.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restart( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );

		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			// Belt and braces over the permission callback. This one
			// discards a record of what was configured, and a capability
			// check that exists in only one place is a capability check
			// one refactor away from not existing.
			return ApiResponse::error( ErrorCode::FORBIDDEN, 'Your account does not have access to this.', 403 );
		}

		return ApiResponse::ok( $this->state->restart() );
	}
}
