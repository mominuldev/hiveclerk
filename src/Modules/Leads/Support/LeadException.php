<?php
/**
 * Lead operation failure.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Support;

use Hiveclerk\Api\ErrorCode;
use RuntimeException;

/**
 * A refusal the service layer makes, carrying the code the API returns.
 *
 * The controller translates it rather than deciding it. Where a merge is
 * legal is a question about leads, not about HTTP, and answering it in
 * the controller would mean answering it again for every other caller.
 */
final class LeadException extends RuntimeException {

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
