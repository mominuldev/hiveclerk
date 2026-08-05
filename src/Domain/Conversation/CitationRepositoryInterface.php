<?php
/**
 * Citation repository contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Conversation;

/**
 * Persistence for message citations.
 */
interface CitationRepositoryInterface {

	/**
	 * Store the citations for one message.
	 *
	 * @param int                  $messageId Message.
	 * @param array<int, Citation> $citations Citations, in rank order.
	 * @return void
	 */
	public function saveFor( int $messageId, array $citations ): void;

	/**
	 * Citations for a set of messages, keyed by message id.
	 *
	 * Batched because a transcript renders every message at once, and one
	 * query per message is the shape that makes a fifty-turn conversation
	 * slow in a way nobody notices until a customer has one.
	 *
	 * @param array<int, int> $messageIds Message ids.
	 * @return array<int, array<int, Citation>>
	 */
	public function forMessages( array $messageIds ): array;
}
