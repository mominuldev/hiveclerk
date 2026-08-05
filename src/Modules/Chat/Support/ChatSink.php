<?php
/**
 * Where a reply is written as it is produced.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Support;

/**
 * The seam between generating a reply and delivering it.
 *
 * TD-2 commits the product to two transports, and a buffering host is not
 * a rare case — it is a normal shared host. If ChatService knew about SSE,
 * the polling fallback would be a second copy of the orchestration, and
 * the two copies would drift: one would get the guardrail fix and the
 * other would not, and the customers on the buffering host would be the
 * ones running the older logic. So generation writes to this interface and
 * the transport is a constructor argument.
 *
 * Every method returns whether the recipient is still there. A visitor who
 * closed the tab is still being billed for tokens nobody will read, and
 * the only way PHP learns they left is by trying to write to them.
 */
interface ChatSink {

	/**
	 * The reply is about to begin.
	 *
	 * @param string $messageId      Assistant message uuid.
	 * @param string $conversationId Conversation uuid.
	 * @return bool Whether the recipient is still connected.
	 */
	public function start( string $messageId, string $conversationId ): bool;

	/**
	 * More text was generated.
	 *
	 * @param string $text Increment.
	 * @return bool Whether the recipient is still connected.
	 */
	public function delta( string $text ): bool;

	/**
	 * Replace everything shown so far.
	 *
	 * Used when a guardrail rejects a reply that has already been streamed.
	 * The visitor has seen text by then, so there is no version of this
	 * that is invisible — the honest options are to replace it or to leave
	 * it, and leaving a reply the guardrails rejected is not an option.
	 *
	 * @param string $text Replacement text.
	 * @return bool Whether the recipient is still connected.
	 */
	public function replace( string $text ): bool;

	/**
	 * The sources this reply leaned on.
	 *
	 * @param array<int, array<string, mixed>> $citations Citation payloads.
	 * @return bool Whether the recipient is still connected.
	 */
	public function citations( array $citations ): bool;

	/**
	 * Generation finished.
	 *
	 * @param array<string, mixed> $payload Closing metadata.
	 * @return bool Whether the recipient is still connected.
	 */
	public function done( array $payload ): bool;

	/**
	 * Generation failed.
	 *
	 * @param string $code        Machine-readable error code.
	 * @param string $message     Visitor-facing text.
	 * @param bool   $recoverable Whether retrying could work.
	 * @return bool Whether the recipient is still connected.
	 */
	public function error( string $code, string $message, bool $recoverable = true ): bool;
}
