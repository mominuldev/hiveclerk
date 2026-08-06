<?php
/**
 * The model-access port.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

use Hiveclerk\Domain\Usage\UsageKind;

/**
 * One of the two seams the hosted product is extracted along.
 *
 * The other is `VectorStoreInterface`. Both exist for the same reason:
 * swapping a customer's own provider key for a metered gateway must be a
 * container binding, not a rewrite of every caller — and the callers are
 * the services that would be hardest to change safely, because they are
 * the ones that spend money.
 *
 * It is also what makes the chat orchestration testable. `AiService` is
 * final, deliberately; a test that wants to assert what happens when a
 * provider fails mid-stream needs a substitute, and the honest way to
 * provide one is a published contract rather than a relaxed keyword.
 *
 * @see docs/09-api-specification.md §5
 * @see docs/06-system-architecture.md §15
 */
interface AiServiceInterface {

	/**
	 * Produce a complete reply and record what it cost.
	 *
	 * @param string            $providerId     Provider identifier.
	 * @param CompletionRequest $request        Request.
	 * @param UsageKind         $kind           What the call is for.
	 * @param int|null          $agentId        Clerk to charge.
	 * @param int|null          $conversationId Conversation to charge.
	 * @return Completion
	 *
	 * @throws ProviderException When the call fails.
	 */
	public function complete(
		string $providerId,
		CompletionRequest $request,
		UsageKind $kind = UsageKind::Chat,
		?int $agentId = null,
		?int $conversationId = null
	): Completion;

	/**
	 * Stream a reply, recording what it cost when it finishes.
	 *
	 * The callback returns false to stop generation early — which is what
	 * a departed visitor looks like from here.
	 *
	 * @param string                      $providerId     Provider identifier.
	 * @param CompletionRequest           $request        Request.
	 * @param callable(StreamEvent): bool $onEvent        Event sink.
	 * @param UsageKind                   $kind           What the call is for.
	 * @param int|null                    $agentId        Clerk to charge.
	 * @param int|null                    $conversationId Conversation to charge.
	 * @return void
	 *
	 * @throws ProviderException When the call fails before any text arrives.
	 */
	public function stream(
		string $providerId,
		CompletionRequest $request,
		callable $onEvent,
		UsageKind $kind = UsageKind::Chat,
		?int $agentId = null,
		?int $conversationId = null
	): void;

	/**
	 * Turn a batch of texts into vectors and record what it cost.
	 *
	 * @param EmbeddingModel     $pin     Pinned provider and model.
	 * @param array<int, string> $texts   Inputs, in order.
	 * @param int                $timeout Seconds.
	 * @param EmbeddingTask      $task    What the vectors will be used for.
	 * @return EmbeddingBatch
	 *
	 * @throws ProviderException When the provider cannot embed or the call fails.
	 */
	public function embed(
		EmbeddingModel $pin,
		array $texts,
		int $timeout = 60,
		EmbeddingTask $task = EmbeddingTask::Document
	): EmbeddingBatch;
}
