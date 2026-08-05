<?php
/**
 * REST controller base.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Api;

use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Shared\Pagination;
use WP_Error;
use WP_REST_Request;

/**
 * Shared behaviour for every controller.
 */
abstract class AbstractController {

	public const NAMESPACE = 'hiveclerk/v1';

	/**
	 * Register this controller's routes.
	 *
	 * @return void
	 */
	abstract public function registerRoutes(): void;

	/**
	 * Build a permission callback that requires a capability.
	 *
	 * Never returns __return_true. An automated test asserts that every
	 * registered route has a non-trivial permission callback, so a route
	 * added without one fails CI rather than shipping open.
	 *
	 * @param string $capability Required capability.
	 * @return callable(WP_REST_Request): (bool|WP_Error)
	 */
	protected function requires( string $capability ): callable {
		return static function () use ( $capability ): bool|WP_Error {
			if ( ! is_user_logged_in() ) {
				return ApiResponse::error(
					ErrorCode::UNAUTHORIZED,
					'You need to sign in.',
					401
				);
			}

			if ( ! current_user_can( $capability ) ) {
				return ApiResponse::error(
					ErrorCode::FORBIDDEN,
					'Your account does not have access to this.',
					403
				);
			}

			return true;
		};
	}

	/**
	 * Throttle results already produced this request, keyed by bucket.
	 *
	 * @var array<string, WP_Error|array<string, string>>
	 */
	private array $throttled = array();

	/**
	 * Apply a rate limit, returning an error response when exceeded.
	 *
	 * ## Why the result is memoised
	 *
	 * WordPress calls a `permission_callback` **twice** per request: once
	 * to authorise the call, and again afterwards from
	 * `rest_send_allow_header()`, which re-runs every handler's callback
	 * to decide which methods to advertise in the `Allow` header.
	 *
	 * A permission callback with a side effect therefore has that side
	 * effect twice, and this one consumes a unit of the customer's rate
	 * limit. Left alone, every public ceiling in the product is half what
	 * it says: a widget configured for twelve messages a minute starts
	 * refusing at six, and the visitor is told they are going too fast
	 * when they are not.
	 *
	 * A controller instance serves one route of one request, so caching
	 * here is exactly per-request.
	 *
	 * @param RateLimiter $limiter Limiter.
	 * @param string      $bucket  Bucket key.
	 * @param int         $limit   Ceiling.
	 * @param int         $window  Window in seconds.
	 * @return WP_Error|array<string, string> Error, or headers to attach.
	 */
	protected function throttle(
		RateLimiter $limiter,
		string $bucket,
		int $limit,
		int $window = 60
	): WP_Error|array {
		if ( isset( $this->throttled[ $bucket ] ) ) {
			return $this->throttled[ $bucket ];
		}

		$result = $limiter->hit( $bucket, $limit, $window );

		if ( ! $result->allowed ) {
			$error = ApiResponse::error(
				ErrorCode::RATE_LIMITED,
				sprintf( 'Too many requests. Try again in %d seconds.', $result->resetIn ),
				429
			);

			$error->add_data(
				array_merge(
					array( 'status' => 429 ),
					array( 'retry_after' => $result->resetIn )
				)
			);

			$this->throttled[ $bucket ] = $error;

			return $error;
		}

		$this->throttled[ $bucket ] = $result->headers();

		return $this->throttled[ $bucket ];
	}

	/**
	 * Build a Pagination from request parameters.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return Pagination
	 */
	protected function pagination( WP_REST_Request $request ): Pagination {
		return Pagination::fromRequest(
			$request->get_param( 'page' ),
			$request->get_param( 'per_page' )
		);
	}

	/**
	 * Read a string parameter, trimmed, or null when absent or blank.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @param string                                $key     Parameter name.
	 * @return string|null
	 */
	protected function stringParam( WP_REST_Request $request, string $key ): ?string {
		$value = $request->get_param( $key );

		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed = trim( $value );

		return '' === $trimmed ? null : $trimmed;
	}

	/**
	 * Standard collection arguments every list route accepts.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function collectionArgs(): array {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 25,
				'minimum'           => 1,
				'maximum'           => Pagination::MAX_PER_PAGE,
				'sanitize_callback' => 'absint',
			),
			'search'   => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
