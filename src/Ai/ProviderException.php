<?php
/**
 * Provider failure.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

use RuntimeException;
use Throwable;

/**
 * A call to a model provider failed.
 *
 * Carries whether a retry could plausibly succeed, because the caller's
 * decision differs sharply: a 429 or a 503 should be re-queued with
 * backoff, while a 401 will fail identically forever and must surface to
 * the operator instead of burning the queue.
 */
final class ProviderException extends RuntimeException {

	/**
	 * Construct.
	 *
	 * @param string         $message   Message safe to show an operator.
	 * @param string         $provider  Provider identifier.
	 * @param int            $status    HTTP status, 0 for transport failures.
	 * @param bool           $retryable Whether a retry could succeed.
	 * @param Throwable|null $previous  Underlying error.
	 */
	public function __construct(
		string $message,
		public readonly string $provider = '',
		public readonly int $status = 0,
		public readonly bool $retryable = false,
		?Throwable $previous = null
	) {
		parent::__construct( $message, $status, $previous );
	}

	/**
	 * The provider rejected the credentials.
	 *
	 * @param string $provider Provider identifier.
	 * @param string $message  Detail.
	 * @return self
	 */
	public static function unauthorised( string $provider, string $message = '' ): self {
		return new self(
			'' !== $message ? $message : 'The provider rejected this API key.',
			$provider,
			401
		);
	}

	/**
	 * The provider is temporarily unavailable or rate limiting us.
	 *
	 * @param string $provider Provider identifier.
	 * @param int    $status   HTTP status.
	 * @param string $message  Detail.
	 * @return self
	 */
	public static function transient( string $provider, int $status, string $message ): self {
		return new self( $message, $provider, $status, true );
	}

	/**
	 * The request never reached the provider.
	 *
	 * Retryable: a DNS blip or a connect timeout on shared hosting is the
	 * most common cause and usually clears on its own.
	 *
	 * @param string         $provider Provider identifier.
	 * @param string         $message  Detail.
	 * @param Throwable|null $previous Underlying error.
	 * @return self
	 */
	public static function unreachable(
		string $provider,
		string $message,
		?Throwable $previous = null
	): self {
		return new self( $message, $provider, 0, true, $previous );
	}

	/**
	 * Whether a status code should be retried.
	 *
	 * @param int $status HTTP status.
	 * @return bool
	 */
	public static function isRetryableStatus( int $status ): bool {
		return 429 === $status || $status >= 500;
	}
}
