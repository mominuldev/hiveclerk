<?php
/**
 * A controller that exposes the protected throttle helper.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Api;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Core\Support\RateLimiter;
use WP_Error;

/**
 * A controller that exposes the protected throttle helper.
 *
 * @internal
 */
final class ThrottleProbe extends AbstractController {

	public function registerRoutes(): void {
	}

	/**
	 * Consume one unit, the way a permission callback does.
	 *
	 * @param RateLimiter $limiter Limiter.
	 * @param string      $bucket  Bucket.
	 * @param int         $limit   Ceiling.
	 * @return WP_Error|array<string, string>
	 */
	public function consume( RateLimiter $limiter, string $bucket, int $limit ): WP_Error|array {
		return $this->throttle( $limiter, $bucket, $limit );
	}
}
