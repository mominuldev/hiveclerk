<?php
/**
 * Conversation entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Conversation;

use DateTimeImmutable;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * One visitor's exchange with one clerk.
 */
final class Conversation {

	/**
	 * Construct.
	 *
	 * @param int|null               $id             Storage id.
	 * @param Uuid                   $uuid           Public identifier.
	 * @param int                    $agentId        Clerk that handled it.
	 * @param int|null               $visitorId      Visitor, when known.
	 * @param int|null               $leadId         Lead, once identified.
	 * @param ConversationStatus     $status         Lifecycle state.
	 * @param string|null            $language       Detected language.
	 * @param string|null            $pageUrl        Where it started.
	 * @param int                    $messageCount   Messages exchanged.
	 * @param string|null            $summary        Generated summary.
	 * @param bool                   $resolvedByAi   Closed without a human.
	 * @param int                    $totalTokensIn  Prompt tokens.
	 * @param int                    $totalTokensOut Completion tokens.
	 * @param float                  $totalCost      Spend in USD.
	 * @param DateTimeImmutable|null $startedAt      Start time, UTC.
	 */
	public function __construct(
		public ?int $id,
		public Uuid $uuid,
		public int $agentId,
		public ?int $visitorId = null,
		public ?int $leadId = null,
		public ConversationStatus $status = ConversationStatus::Active,
		public ?string $language = null,
		public ?string $pageUrl = null,
		public int $messageCount = 0,
		public ?string $summary = null,
		public bool $resolvedByAi = false,
		public int $totalTokensIn = 0,
		public int $totalTokensOut = 0,
		public float $totalCost = 0.0,
		public ?DateTimeImmutable $startedAt = null,
	) {
	}

	/**
	 * Whether a lead has been attached.
	 *
	 * @return bool
	 */
	public function hasLead(): bool {
		return null !== $this->leadId;
	}

	/**
	 * Total tokens consumed.
	 *
	 * @return int
	 */
	public function totalTokens(): int {
		return $this->totalTokensIn + $this->totalTokensOut;
	}

	/**
	 * Whether this conversation counts toward the North Star metric.
	 *
	 * A qualified conversation captured a lead, resolved a question without
	 * escalation, or influenced an order. Volume alone is not success.
	 *
	 * @return bool
	 */
	public function isQualified(): bool {
		return $this->hasLead() || $this->resolvedByAi;
	}
}
