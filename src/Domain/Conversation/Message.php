<?php
/**
 * Message entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Conversation;

use DateTimeImmutable;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * A single turn in a conversation.
 */
final class Message {

	/**
	 * Construct.
	 *
	 * @param int|null               $id             Storage id.
	 * @param Uuid                   $uuid           Public identifier.
	 * @param int                    $conversationId Owning conversation.
	 * @param MessageRole            $role           Author.
	 * @param string                 $content        Raw text.
	 * @param string|null            $provider       Model provider used.
	 * @param string|null            $model          Model used.
	 * @param int                    $tokensIn       Prompt tokens.
	 * @param int                    $tokensOut      Completion tokens.
	 * @param float|null             $cost           Spend in USD, null when the model has no published price.
	 * @param int|null               $latencyMs      Time to complete.
	 * @param float|null             $retrievalScore Best chunk score.
	 * @param bool                   $isGrounded     Supported by a citation.
	 * @param int|null               $rating         Visitor feedback, -1 or 1.
	 * @param int|null               $wpUserId       Staff member who wrote it, for a human reply.
	 * @param DateTimeImmutable|null $createdAt      Creation time, UTC.
	 * @param array<int, string>     $guardrailFlags What the guardrails noticed.
	 */
	public function __construct(
		public ?int $id,
		public Uuid $uuid,
		public int $conversationId,
		public MessageRole $role,
		public string $content,
		public ?string $provider = null,
		public ?string $model = null,
		public int $tokensIn = 0,
		public int $tokensOut = 0,
		public ?float $cost = null,
		public ?int $latencyMs = null,
		public ?float $retrievalScore = null,
		public bool $isGrounded = false,
		public ?int $rating = null,
		public ?int $wpUserId = null,
		public ?DateTimeImmutable $createdAt = null,
		public array $guardrailFlags = array(),
	) {
	}

	/**
	 * Whether this reply met the clerk's confidence threshold.
	 *
	 * @param float $threshold Minimum acceptable retrieval score.
	 * @return bool
	 */
	public function meetsConfidence( float $threshold ): bool {
		if ( null === $this->retrievalScore ) {
			return false;
		}

		return $this->retrievalScore >= $threshold;
	}

	/**
	 * Total tokens for this turn.
	 *
	 * @return int
	 */
	public function totalTokens(): int {
		return $this->tokensIn + $this->tokensOut;
	}
}
