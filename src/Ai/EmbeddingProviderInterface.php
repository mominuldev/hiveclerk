<?php
/**
 * Embedding provider contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

/**
 * A provider that can turn text into vectors.
 *
 * Separate from LlmProviderInterface rather than bolted onto it, because
 * not every chat provider embeds — Anthropic does not offer an embedding
 * model at all. Folding the method into the chat interface would force
 * every adapter to carry a method that throws, and callers to discover
 * which by trying it.
 */
interface EmbeddingProviderInterface {

	/**
	 * Embedding models this key can reach.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return array<int, Model>
	 *
	 * @throws ProviderException When the provider cannot be reached.
	 */
	public function embeddingModels( Credentials $credentials ): array;

	/**
	 * The embedding model to use when the operator has not chosen one.
	 *
	 * @return string
	 */
	public function defaultEmbeddingModel(): string;

	/**
	 * The largest number of inputs this provider accepts in one call.
	 *
	 * @return int
	 */
	public function maxBatchSize(): int;

	/**
	 * Turn a batch of texts into vectors.
	 *
	 * Vectors come back in input order. Implementations must sort by the
	 * provider's own index rather than trusting response order, and must
	 * fail rather than return a short batch — a silently truncated
	 * response attaches every subsequent vector to the wrong chunk.
	 *
	 * @param Credentials       $credentials Credentials.
	 * @param array<int, string> $texts      Inputs, in order.
	 * @param string            $model       Model identifier.
	 * @param int               $timeout     Seconds.
	 * @param EmbeddingTask     $task        What the vectors will be used for.
	 * @return EmbeddingBatch
	 *
	 * @throws ProviderException When the call fails or the batch is short.
	 */
	public function embed(
		Credentials $credentials,
		array $texts,
		string $model,
		int $timeout = 60,
		EmbeddingTask $task = EmbeddingTask::Document
	): EmbeddingBatch;
}
