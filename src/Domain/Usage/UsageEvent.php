<?php
/**
 * One metered provider call.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Usage;

/**
 * A single call to a model provider, with what it cost.
 *
 * One row per call rather than a running counter. Counters cannot answer
 * the questions the product needs to answer — which clerk is expensive,
 * which day spend jumped, whether a re-index or a traffic spike caused it
 * — and a counter that drifts cannot be reconciled against a provider
 * invoice, while a log of calls can.
 *
 * Cost is nullable and that nullability is the point: an unpriced model
 * records no cost rather than a zero. Zero is a claim that the call was
 * free, which is almost never true and quietly understates the total.
 */
final class UsageEvent {

	/**
	 * Construct.
	 *
	 * @param UsageKind  $kind           What the call was for.
	 * @param string     $provider       Provider identifier.
	 * @param string     $model          Model identifier.
	 * @param int        $tokensIn       Input tokens as reported.
	 * @param int        $tokensOut      Output tokens as reported.
	 * @param float|null $cost           Cost in USD, null when unpriced.
	 * @param int|null   $agentId        Clerk this is charged to.
	 * @param int|null   $conversationId Conversation this belongs to.
	 * @param int|null   $latencyMs      Round-trip time.
	 */
	public function __construct(
		public readonly UsageKind $kind,
		public readonly string $provider,
		public readonly string $model,
		public readonly int $tokensIn = 0,
		public readonly int $tokensOut = 0,
		public readonly ?float $cost = null,
		public readonly ?int $agentId = null,
		public readonly ?int $conversationId = null,
		public readonly ?int $latencyMs = null
	) {
	}

	/**
	 * Total tokens moved.
	 *
	 * @return int
	 */
	public function totalTokens(): int {
		return $this->tokensIn + $this->tokensOut;
	}

	/**
	 * Whether a price is known for this call.
	 *
	 * @return bool
	 */
	public function isPriced(): bool {
		return null !== $this->cost;
	}

	/**
	 * The same event charged to a clerk and conversation.
	 *
	 * @param int|null $agentId        Clerk id.
	 * @param int|null $conversationId Conversation id.
	 * @return self
	 */
	public function attributedTo( ?int $agentId, ?int $conversationId = null ): self {
		return new self(
			$this->kind,
			$this->provider,
			$this->model,
			$this->tokensIn,
			$this->tokensOut,
			$this->cost,
			$agentId,
			$conversationId,
			$this->latencyMs
		);
	}
}
