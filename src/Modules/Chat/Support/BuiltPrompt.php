<?php
/**
 * An assembled prompt and what went into it.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Support;

use Hiveclerk\Ai\CompletionRequest;
use Hiveclerk\Domain\Knowledge\RetrievedChunk;

/**
 * The request to send, plus the accounting of what was left out.
 *
 * The dropped counts are not diagnostics for their own sake. A clerk that
 * suddenly answers worse is nearly always a clerk whose context is being
 * truncated — the retrieval was fine and the chunk never reached the
 * model. Without these two numbers that failure is indistinguishable from
 * the model simply being unhelpful, and the test console in Sprint 6 shows
 * them for exactly that reason.
 */
final class BuiltPrompt {

	/**
	 * Construct.
	 *
	 * @param CompletionRequest            $request        Ready to send.
	 * @param array<int, RetrievedChunk>   $grounding      Chunks that reached the model, in rank order.
	 * @param string                       $fence          Nonce-suffixed tag name used for untrusted blocks.
	 * @param int                          $droppedChunks  Chunks cut for budget.
	 * @param int                          $droppedTurns   History turns cut for budget.
	 * @param int                          $estimatedTokens Estimated input size.
	 */
	public function __construct(
		public readonly CompletionRequest $request,
		public readonly array $grounding,
		public readonly string $fence,
		public readonly int $droppedChunks = 0,
		public readonly int $droppedTurns = 0,
		public readonly int $estimatedTokens = 0
	) {
	}

	/**
	 * Whether any source material reached the model at all.
	 *
	 * @return bool
	 */
	public function isGrounded(): bool {
		return array() !== $this->grounding;
	}
}
