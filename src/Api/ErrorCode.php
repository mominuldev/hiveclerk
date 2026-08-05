<?php
/**
 * API error codes.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Api;

/**
 * Stable machine-readable error codes.
 *
 * These are part of the API contract. Messages may be reworded or
 * translated freely; codes may not change without a version bump.
 */
final class ErrorCode {

	public const UNAUTHORIZED          = 'hvc_unauthorized';
	public const FORBIDDEN             = 'hvc_forbidden';
	public const NOT_FOUND             = 'hvc_not_found';
	public const VALIDATION_FAILED     = 'hvc_validation_failed';
	public const RATE_LIMITED          = 'hvc_rate_limited';
	public const LICENCE_REQUIRED      = 'hvc_licence_required';
	public const QUOTA_EXCEEDED        = 'hvc_quota_exceeded';
	public const PROVIDER_ERROR        = 'hvc_provider_error';
	public const PROVIDER_UNCONFIGURED = 'hvc_provider_unconfigured';
	public const CONFLICT              = 'hvc_conflict';
	public const SERVER_ERROR          = 'hvc_server_error';

	/**
	 * HTTP status for each code.
	 *
	 * @return array<string, int>
	 */
	public static function statuses(): array {
		return array(
			self::UNAUTHORIZED          => 401,
			self::FORBIDDEN             => 403,
			self::NOT_FOUND             => 404,
			self::VALIDATION_FAILED     => 422,
			self::RATE_LIMITED          => 429,
			self::LICENCE_REQUIRED      => 402,
			self::QUOTA_EXCEEDED        => 402,
			self::PROVIDER_ERROR        => 502,
			self::PROVIDER_UNCONFIGURED => 409,
			self::CONFLICT              => 409,
			self::SERVER_ERROR          => 500,
		);
	}

	/**
	 * HTTP status for a code, defaulting to 400.
	 *
	 * @param string $code Error code.
	 * @return int
	 */
	public static function status( string $code ): int {
		return self::statuses()[ $code ] ?? 400;
	}
}
