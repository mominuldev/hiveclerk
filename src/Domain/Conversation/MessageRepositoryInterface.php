<?php
/**
 * Message repository contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Conversation;

use Hiveclerk\Domain\Shared\Uuid;

/**
 * Persistence for messages.
 */
interface MessageRepositoryInterface {

	/**
	 * Find by public identifier.
	 *
	 * @param Uuid $uuid Public identifier.
	 * @return Message|null
	 */
	public function findByUuid( Uuid $uuid ): ?Message;

	/**
	 * Full transcript in chronological order.
	 *
	 * @param int $conversationId Conversation.
	 * @return array<int, Message>
	 */
	public function transcript( int $conversationId ): array;

	/**
	 * The most recent turns, oldest first, for prompt history.
	 *
	 * @param int $conversationId Conversation.
	 * @param int $limit          Maximum turns.
	 * @return array<int, Message>
	 */
	public function recent( int $conversationId, int $limit = 10 ): array;

	/**
	 * Insert a message.
	 *
	 * @param Message $message Message.
	 * @return Message
	 */
	public function save( Message $message ): Message;

	/**
	 * Record visitor feedback.
	 *
	 * @param Uuid        $uuid    Message identifier.
	 * @param int         $rating  -1 or 1.
	 * @param string|null $comment Optional note.
	 * @return bool
	 */
	public function rate( Uuid $uuid, int $rating, ?string $comment = null ): bool;

	/**
	 * Count messages in a conversation.
	 *
	 * @param int $conversationId Conversation.
	 * @return int
	 */
	public function countFor( int $conversationId ): int;
}
