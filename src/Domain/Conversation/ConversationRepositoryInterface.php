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

	/**
	 * A lead's conversations, newest first (FR-LED-06).
	 *
	 * @param int $leadId Lead storage id.
	 * @param int $limit  Maximum rows.
	 * @return array<int, Conversation>
	 */
	public function forLead( int $leadId, int $limit = 20 ): array;

	/**
	 * Point a conversation at a lead.
	 *
	 * A single-column write rather than a save(): capture runs mid-reply,
	 * while the caller still holds a conversation whose counters it has
	 * not finished updating.
	 *
	 * @param int $conversationId Conversation storage id.
	 * @param int $leadId         Lead storage id.
	 * @return bool
	 */
	public function attachLead( int $conversationId, int $leadId ): bool;

	/**
	 * Move every conversation from one lead onto another (FR-LED-08).
	 *
	 * @param int $from Lead being merged away.
	 * @param int $to   Surviving lead.
	 * @return int Rows moved.
	 */
	public function reassignLead( int $from, int $to ): int;

	/**
	 * Ids of conversations that started before a cutoff, oldest first.
	 *
	 * Ids rather than entities: the purge job needs to delete them, not to
	 * read them, and hydrating a thousand transcripts to throw them away
	 * is how a retention job runs out of memory on the site with the most
	 * data to purge.
	 *
	 * @param string $cutoff UTC timestamp, Y-m-d H:i:s.
	 * @param int    $limit  Batch size.
	 * @return array<int, int>
	 */
	public function idsStartedBefore( string $cutoff, int $limit ): array;

	/**
	 * How many conversations are older than a cutoff.
	 *
	 * @param string $cutoff UTC timestamp, Y-m-d H:i:s.
	 * @return int
	 */
	public function countStartedBefore( string $cutoff ): int;

	/**
	 * Delete a batch of conversations and everything hanging off them.
	 *
	 * @param array<int, int> $ids Storage ids.
	 * @return int Conversations deleted.
	 */
	public function purge( array $ids ): int;

	/**
	 * Per-clerk totals since a date, for the roster cards.
	 *
	 * One grouped query rather than one query per clerk: the roster is
	 * rendered on every screen, and N+1 there is N+1 everywhere.
	 *
	 * @param array<int, int> $agentIds Clerks to report on.
	 * @param string          $since    UTC timestamp, Y-m-d H:i:s.
	 * @return array<int, array{conversations: int, resolved: int, handoffs: int, cost: float}>
	 */
	public function statsForAgents( array $agentIds, string $since ): array;
}
