<?php
/**
 * Licence endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Api\Controllers;

use DateTimeZone;
use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Licence\LicenceService;
use Hiveclerk\Core\Licence\LicenceStatus;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Core\Support\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Activation, deactivation and re-check (FR-SYS-01).
 *
 * `manage_settings` throughout, which is the capability that also holds
 * the provider API keys. A licence key is a billing credential: somebody
 * who can read the roster and answer conversations has no business
 * moving a customer's seats between sites.
 *
 * There is no endpoint that returns a decrypted licence key, for the same
 * reason there is none for a provider key. `GET` answers with the masked
 * display value and a boolean.
 */
final class LicenceController extends AbstractController {

	/**
	 * Activation attempts allowed per minute.
	 *
	 * Each one is an outbound HTTP request to our own licence server, so
	 * an unthrottled endpoint is a way for one authenticated user to
	 * point a customer's site at us as a load generator. Low, because
	 * nobody legitimately activates a licence twice in a minute.
	 */
	private const ATTEMPTS = 6;

	/**
	 * Construct.
	 *
	 * @param LicenceService $licences Licence state.
	 * @param RateLimiter    $limiter  Rate limiting.
	 * @param ClockInterface $clock    Clock.
	 */
	public function __construct(
		private readonly LicenceService $licences,
		private readonly RateLimiter $limiter,
		private readonly ClockInterface $clock
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
			'/admin/settings/licence',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $capability,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'activate' ),
					'permission_callback' => $capability,
					'args'                => array(
						'key' => array(
							'type'              => 'string',
							'required'          => true,
							// Not sanitize_text_field: a licence key that
							// has been silently "cleaned" is a key that
							// fails at the server with an error pointing
							// at us instead of at the typo. Validated
							// against a shape below and rejected outright
							// if it does not match.
							'sanitize_callback' => static fn ( $value ): string => is_string( $value ) ? trim( $value ) : '',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'deactivate' ),
					'permission_callback' => $capability,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/settings/licence/recheck',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'recheck' ),
				'permission_callback' => $capability,
			)
		);
	}

	/**
	 * The current licence.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return ApiResponse::ok( $this->present() );
	}

	/**
	 * Activate a key.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function activate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$throttle = $this->throttle( $this->limiter, $this->bucket(), self::ATTEMPTS );

		if ( $throttle instanceof WP_Error ) {
			return $throttle;
		}

		$key = (string) $request->get_param( 'key' );

		if ( ! $this->looksLikeKey( $key ) ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				'That does not look like a licence key. Check it against your receipt.',
				422,
				array( 'key' => array( 'Expected 16 to 128 characters, letters, digits and dashes.' ) )
			);
		}

		$licence = $this->licences->activate( $key );

		if ( LicenceStatus::Active !== $licence->status ) {
			// A 200 carrying the refusal rather than an error status. The
			// server answered, the key is now the site's stored state,
			// and the screen needs to render that state with the reason
			// attached — which a WP_Error body cannot carry.
			return ApiResponse::ok( $this->present(), array(), 200 );
		}

		return ApiResponse::ok( $this->present() );
	}

	/**
	 * Release this site's seat.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function deactivate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );

		$throttle = $this->throttle( $this->limiter, $this->bucket(), self::ATTEMPTS );

		if ( $throttle instanceof WP_Error ) {
			return $throttle;
		}

		$this->licences->deactivate();

		return ApiResponse::ok( $this->present() );
	}

	/**
	 * Re-check the stored key.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function recheck( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );

		$throttle = $this->throttle( $this->limiter, $this->bucket(), self::ATTEMPTS );

		if ( $throttle instanceof WP_Error ) {
			return $throttle;
		}

		if ( ! $this->licences->hasKey() ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, 'There is no licence key to check.', 404 );
		}

		$this->licences->recheck();

		return ApiResponse::ok( $this->present() );
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, mixed>
	 */
	private function present(): array {
		return $this->licences->current()->toArray(
			$this->clock->now()->setTimezone( new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Whether a string is shaped like a key we would send.
	 *
	 * Shape only. Whether the key is real is the server's decision, and
	 * guessing at it here would reject keys from a format we have not
	 * shipped yet.
	 *
	 * @param string $key Candidate.
	 * @return bool
	 */
	private function looksLikeKey( string $key ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $key );
	}

	/**
	 * Rate-limit bucket for the current user.
	 *
	 * @return string
	 */
	private function bucket(): string {
		return 'licence:' . get_current_user_id();
	}
}
