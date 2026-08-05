<?php
/**
 * A refusal the operator needs to read.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Agents\Support;

use RuntimeException;

/**
 * Thrown when a clerk cannot be changed the way it was asked to be.
 *
 * Carries the API error code with it so the controller does not have to
 * infer one from the message — the code is the contract, the message is
 * the prose, and mapping English back to `hvc_licence_required` in a
 * catch block is how the two drift apart.
 */
final class AgentException extends RuntimeException {

	/**
	 * Construct.
	 *
	 * @param string $errorCode Stable API error code.
	 * @param string $message   Operator-facing message.
	 * @param int    $status    HTTP status.
	 */
	public function __construct(
		public readonly string $errorCode,
		string $message,
		public readonly int $status = 422
	) {
		parent::__construct( $message );
	}
}
