<?php
/**
 * Email operation failure.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Email\Support;

use Hiveclerk\Api\ErrorCode;
use RuntimeException;

/**
 * A refusal the service layer makes, carrying the code the API returns.
 *
 * Whether a sequence is ready to activate is a question about sequences,
 * not about HTTP. The controller translates the answer rather than
 * deciding it, so the same rule holds for a WP-CLI caller or a future
 * import that never passes through a route.
 */
final class EmailException extends RuntimeException {

	/**
	 * Construct.
	 *
	 * @param string $message   What went wrong, in the operator's language.
	 * @param string $errorCode Stable machine code.
	 * @param int    $status    HTTP status.
	 */
	public function __construct(
		string $message,
		public readonly string $errorCode = ErrorCode::VALIDATION_FAILED,
		public readonly int $status = 422
	) {
		parent::__construct( $message );
	}

	/**
	 * The request cannot be satisfied as stated.
	 *
	 * @param string $message Message.
	 * @return self
	 */
	public static function invalid( string $message ): self {
		return new self( $message );
	}

	/**
	 * Something was addressed that does not exist.
	 *
	 * @param string $message Message.
	 * @return self
	 */
	public static function notFound( string $message ): self {
		return new self( $message, ErrorCode::NOT_FOUND, 404 );
	}

	/**
	 * The request contradicts the current state.
	 *
	 * @param string $message Message.
	 * @return self
	 */
	public static function conflict( string $message ): self {
		return new self( $message, ErrorCode::CONFLICT, 409 );
	}
}
