<?php
/**
 * Conversation repository contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Conversation;

use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Persistence for conversations.
 */
interface ConversationRepositoryInterface {

	/**
	 * Find by storage id.
	 *
	 * @param int $id Storage id.
	 * @return Conversation|null
	 */
	public function find( int $id ): ?Conversation;

	/**
	 * Find by public identifier.
	 *
	 * @param Uuid $uuid Public identifier.
	 * @return Conversation|null
	 */
	public function findByUuid( Uuid $uuid ): ?Conversation;

	/**
	 * List conversations.
	 *
	 * @param Pagination              $pagination Page request.
	 * @param array<string, mixed>    $filters    agent_id, status, has_lead.
	 * @return array<int, Conversation>
	 */
	public function paginate( Pagination $pagination, array $filters = array() ): array;

	/**
	 * Count conversations.
	 *
	 * @param array<string, mixed> $filters Same shape as paginate().
	 * @return int
	 */
	public function count( array $filters = array() ): int;

	/**
	 * Insert or update.
	 *
	 * @param Conversation $conversation Conversation.
	 * @return Conversation
	 */
	public function save( Conversation $conversation ): Conversation;

	/**
	 * Delete a conversation and everything hanging off it.
	 *
	 * @param int $id Storage id.
	 * @return bool
	 */
	public function delete( int $id ): bool;

	/**
	 * Conversations waiting on a human, oldest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, Conversation>
	 */
	public function awaitingHandoff( int $limit = 20 ): array;
}
