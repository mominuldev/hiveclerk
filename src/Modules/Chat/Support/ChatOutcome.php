<?php
/**
 * What one exchange produced.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Support;

/**
 * The record of a reply, after it has been delivered and stored.
 *
 * Returned rather than merely emitted because the two transports need it
 * for different things: the streaming controller has already sent
 * everything and only needs to know whether to log, while the polling
 * controller writes the closing frame from this.
 */
final class ChatOutcome {

	/**
	 * Construct.
	 *
	 * @param string                           $messageId  Assistant message uuid.
	 * @param string                           $text       The reply as stored.
	 * @param array<int, array<string, mixed>> $citations  Citation payloads.
	 * @param int                              $tokensIn   Prompt tokens.
	 * @param int                              $tokensOut  Completion tokens.
	 * @param bool                             $grounded   Whether a source supported it.
	 * @param array<int, string>               $flags      Guardrail flags recorded.
	 * @param bool                             $blocked    Whether a guardrail replaced the reply.
	 * @param string|null                      $errorCode  Error code when generation failed.
	 */
	public function __construct(
		public readonly string $messageId,
		public readonly string $text,
		public readonly array $citations = array(),
		public readonly int $tokensIn = 0,
		public readonly int $tokensOut = 0,
		public readonly bool $grounded = false,
		public readonly array $flags = array(),
		public readonly bool $blocked = false,
		public readonly ?string $errorCode = null
	) {
	}

	/**
	 * Whether generation failed outright.
	 *
	 * @return bool
	 */
	public function failed(): bool {
		return null !== $this->errorCode;
	}
}
