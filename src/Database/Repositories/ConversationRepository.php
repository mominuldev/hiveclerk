<?php
/**
 * Conversation repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationNote;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\ConversationStatus;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Stores conversations.
 */
final class ConversationRepository extends AbstractRepository implements ConversationRepositoryInterface {

	protected function table(): string {
		return Schema::CONVERSATIONS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'started_at', 'last_message_at', 'message_count', 'total_cost' );
	}

	public function find( int $id ): ?Conversation {
		$row = $this->fetchRow( 'id = %d', array( $id ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function findByUuid( Uuid $uuid ): ?Conversation {
		$row = $this->fetchRow( 'uuid = %s', array( $uuid->value ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function paginate( Pagination $pagination, array $filters = array() ): array {
		[ $where, $params ] = $this->buildFilters( $filters );

		$rows = $this->fetchAll(
			$where,
			$params,
			'started_at',
			'DESC',
			$pagination->perPage,
			$pagination->offset()
		);

		return array_map( fn ( array $row ): Conversation => $this->hydrate( $row ), $rows );
	}

	public function count( array $filters = array() ): int {
		[ $where, $params ] = $this->buildFilters( $filters );

		return $this->countWhere( $where, $params );
	}

	public function save( Conversation $conversation ): Conversation {
		$data = array(
			'uuid'             => $conversation->uuid->value,
			'agent_id'         => $conversation->agentId,
			'visitor_id'       => $conversation->visitorId,
			'lead_id'          => $conversation->leadId,
			'status'           => $conversation->status->value,
			'language'         => $conversation->language,
			'page_url'         => $conversation->pageUrl,
			'page_title'       => $conversation->pageTitle,
			'message_count'    => $conversation->messageCount,
			'summary'          => $conversation->summary,
			'sentiment'        => $conversation->sentiment,
			'resolved_by_ai'   => $conversation->resolvedByAi ? 1 : 0,
			'handoff_user_id'  => $conversation->handoffUserId,
			'handoff_at'       => $this->stamp( $conversation->handoffAt ),
			'rating'           => $conversation->rating,
			'starred'          => $conversation->starred ? 1 : 0,
			'tags'             => $this->encodeJson( array_values( $conversation->tags ) ),
			'notes'            => $this->encodeJson( $this->encodeNotes( $conversation->notes ) ),
			'total_tokens_in'  => $conversation->totalTokensIn,
			'total_tokens_out' => $conversation->totalTokensOut,
			'total_cost'       => $conversation->totalCost,
			'last_message_at'  => $this->stamp( $conversation->lastMessageAt ),
			'ended_at'         => $this->stamp( $conversation->endedAt ),
		);

		if ( null === $conversation->id ) {
			$started                 = $conversation->startedAt ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			$data['started_at']      = $this->stamp( $started );
			$conversation->startedAt = $started;

			$id = $this->insertRow( $data );

			if ( null === $id ) {
				return $conversation;
			}

			$conversation->id = $id;

			return $conversation;
		}

		$this->updateRow( $conversation->id, $data );

		return $conversation;
	}

	/**
	 * Delete a conversation and everything hanging off it.
	 *
	 * Children go first, inside a transaction. With no database-level
	 * foreign keys, an interrupted delete would otherwise leave orphaned
	 * messages and citations behind.
	 *
	 * @param int $id Storage id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		return 1 === $this->purge( array( $id ) );
	}

	public function awaitingHandoff( int $limit = 20 ): array {
		$rows = $this->fetchAll(
			'status = %s',
			array( ConversationStatus::HandoffRequested->value ),
			'started_at',
			'ASC',
			$limit
		);

		return array_map( fn ( array $row ): Conversation => $this->hydrate( $row ), $rows );
	}

	public function forLead( int $leadId, int $limit = 20 ): array {
		$rows = $this->fetchAll(
			'lead_id = %d',
			array( $leadId ),
			'started_at',
			'DESC',
			$limit
		);

		return array_map( fn ( array $row ): Conversation => $this->hydrate( $row ), $rows );
	}

	public function attachLead( int $conversationId, int $leadId ): bool {
		$table = $this->tableName();

		// A targeted write rather than a save(). Capture runs while the
		// conversation object in the request has counters the caller is
		// still adding to, and writing the whole row here would persist a
		// half-updated copy of it.
		return $this->execute(
			"UPDATE `{$table}` SET lead_id = %d WHERE id = %d",
			array( $leadId, $conversationId )
		);
	}

	public function reassignLead( int $from, int $to ): int {
		$table = $this->tableName();

		$done = $this->execute(
			"UPDATE `{$table}` SET lead_id = %d WHERE lead_id = %d",
			array( $to, $from )
		);

		return $done ? (int) $this->db->rows_affected : 0;
	}

	public function idsStartedBefore( string $cutoff, int $limit ): array {
		$table = $this->tableName();

		$prepared = $this->db->prepare(
			"SELECT id FROM `{$table}` WHERE started_at < %s ORDER BY started_at ASC LIMIT %d",
			$cutoff,
			$limit
		);

		if ( ! is_string( $prepared ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $this->db->get_col( $prepared );

		return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array();
	}

	public function countStartedBefore( string $cutoff ): int {
		return $this->countWhere( 'started_at < %s', array( $cutoff ) );
	}

	/**
	 * Delete a batch of conversations and their children.
	 *
	 * The whole batch is one transaction. Purging is the one operation in
	 * the product that destroys data on a timer, so a half-deleted
	 * conversation — messages gone, row remaining — is the outcome worth
	 * the most effort to avoid: it reads in the admin as a conversation
	 * that happened and had nothing said in it.
	 *
	 * @param array<int, int> $ids Storage ids.
	 * @return int Conversations deleted.
	 */
	public function purge( array $ids ): int {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( array() === $ids ) {
			return 0;
		}

		$table     = $this->tableName();
		$messages  = Schema::table( Schema::MESSAGES );
		$citations = Schema::table( Schema::MESSAGE_CITATIONS );

		// Placeholders are generated from the count of ids and every value
		// is still bound, so nothing but %d ever reaches the statement.
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->db->query( 'START TRANSACTION' );

		$done = $this->execute(
			"DELETE c FROM `{$citations}` c
			 INNER JOIN `{$messages}` m ON m.id = c.message_id
			 WHERE m.conversation_id IN ({$placeholders})",
			$ids
		);

		$done = $done && $this->execute(
			"DELETE FROM `{$messages}` WHERE conversation_id IN ({$placeholders})",
			$ids
		);

		$done = $done && $this->execute(
			"DELETE FROM `{$table}` WHERE id IN ({$placeholders})",
			$ids
		);

		$this->db->query( $done ? 'COMMIT' : 'ROLLBACK' );
		// phpcs:enable

		return $done ? count( $ids ) : 0;
	}

	public function statsForAgents( array $agentIds, string $since ): array {
		$agentIds = array_values( array_unique( array_map( 'intval', $agentIds ) ) );

		if ( array() === $agentIds ) {
			return array();
		}

		$table        = $this->tableName();
		$placeholders = implode( ', ', array_fill( 0, count( $agentIds ), '%d' ) );

		$prepared = $this->db->prepare(
			"SELECT agent_id,
			        COUNT(*) AS conversations,
			        SUM(CASE WHEN resolved_by_ai = 1 THEN 1 ELSE 0 END) AS resolved,
			        SUM(CASE WHEN status = 'handoff_requested' THEN 1 ELSE 0 END) AS handoffs,
			        SUM(total_cost) AS cost
			 FROM `{$table}`
			 WHERE started_at >= %s AND agent_id IN ({$placeholders})
			 GROUP BY agent_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( array( $since ), $agentIds )
		);

		if ( ! is_string( $prepared ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $prepared, ARRAY_A );

		$stats = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$stats[ (int) $row['agent_id'] ] = array(
				'conversations' => (int) $row['conversations'],
				'resolved'      => (int) $row['resolved'],
				'handoffs'      => (int) $row['handoffs'],
				'cost'          => (float) $row['cost'],
			);
		}

		return $stats;
	}

	/**
	 * Turn a filter array into a WHERE clause and bound parameters.
	 *
	 * Filter keys are matched against a fixed set; an unknown key is
	 * ignored rather than interpolated.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function buildFilters( array $filters ): array {
		$where  = '1=1';
		$params = array();

		if ( isset( $filters['agent_id'] ) && is_numeric( $filters['agent_id'] ) ) {
			$where   .= ' AND agent_id = %d';
			$params[] = (int) $filters['agent_id'];
		}

		if ( isset( $filters['status'] ) && is_string( $filters['status'] ) ) {
			$status = ConversationStatus::tryFrom( $filters['status'] );

			if ( null !== $status ) {
				$where   .= ' AND status = %s';
				$params[] = $status->value;
			}
		}

		if ( isset( $filters['has_lead'] ) ) {
			$where .= $filters['has_lead'] ? ' AND lead_id IS NOT NULL' : ' AND lead_id IS NULL';
		}

		if ( isset( $filters['handoff'] ) && $filters['handoff'] ) {
			// Both handoff states, because "show me the handoffs" means the
			// ones still waiting and the ones a colleague already picked up.
			$where   .= ' AND status IN ( %s, %s )';
			$params[] = ConversationStatus::HandoffRequested->value;
			$params[] = ConversationStatus::HandoffActive->value;
		}

		if ( isset( $filters['starred'] ) && $filters['starred'] ) {
			$where .= ' AND starred = 1';
		}

		if ( isset( $filters['rating'] ) && is_numeric( $filters['rating'] ) ) {
			$where   .= ' AND rating = %d';
			$params[] = (int) $filters['rating'];
		}

		if ( isset( $filters['sentiment'] ) && is_string( $filters['sentiment'] ) && '' !== $filters['sentiment'] ) {
			$where   .= ' AND sentiment = %s';
			$params[] = $filters['sentiment'];
		}

		if ( isset( $filters['tag'] ) && is_string( $filters['tag'] ) && '' !== $filters['tag'] ) {
			// A LIKE over the JSON array. Exact enough for a chip filter and
			// index-free either way, since a JSON predicate cannot use one.
			$where   .= ' AND tags LIKE %s';
			$params[] = '%' . $this->db->esc_like( '"' . $filters['tag'] . '"' ) . '%';
		}

		if ( isset( $filters['search'] ) && is_string( $filters['search'] ) && '' !== trim( $filters['search'] ) ) {
			$like     = '%' . $this->db->esc_like( trim( $filters['search'] ) ) . '%';
			$where   .= ' AND ( summary LIKE %s OR page_title LIKE %s OR page_url LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( isset( $filters['date_from'] ) && is_string( $filters['date_from'] ) ) {
			$where   .= ' AND started_at >= %s';
			$params[] = $filters['date_from'];
		}

		if ( isset( $filters['date_to'] ) && is_string( $filters['date_to'] ) ) {
			$where   .= ' AND started_at <= %s';
			$params[] = $filters['date_to'];
		}

		return array( $where, $params );
	}

	/**
	 * Notes in the shape the JSON column holds.
	 *
	 * @param array<int, ConversationNote> $notes Notes.
	 * @return array<int, array<string, mixed>>
	 */
	private function encodeNotes( array $notes ): array {
		return array_values(
			array_map(
				static fn ( ConversationNote $note ): array => $note->toArray(),
				$notes
			)
		);
	}



	/**
	 * Build a Conversation from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return Conversation
	 */
	private function hydrate( array $row ): Conversation {
		$tags = array_values(
			array_filter(
				$this->json( $row['tags'] ?? null ),
				static fn ( $tag ): bool => is_string( $tag ) && '' !== $tag
			)
		);

		$notes = array();

		foreach ( $this->json( $row['notes'] ?? null ) as $entry ) {
			$note = ConversationNote::fromArray( $entry );

			if ( null !== $note ) {
				$notes[] = $note;
			}
		}

		return new Conversation(
			id: isset( $row['id'] ) ? (int) $row['id'] : null,
			uuid: new Uuid( (string) ( $row['uuid'] ?? '' ) ),
			agentId: (int) ( $row['agent_id'] ?? 0 ),
			visitorId: isset( $row['visitor_id'] ) ? (int) $row['visitor_id'] : null,
			leadId: isset( $row['lead_id'] ) ? (int) $row['lead_id'] : null,
			status: ConversationStatus::fromStorage( isset( $row['status'] ) ? (string) $row['status'] : null ),
			language: isset( $row['language'] ) ? (string) $row['language'] : null,
			pageUrl: isset( $row['page_url'] ) ? (string) $row['page_url'] : null,
			messageCount: (int) ( $row['message_count'] ?? 0 ),
			summary: isset( $row['summary'] ) ? (string) $row['summary'] : null,
			resolvedByAi: (bool) ( $row['resolved_by_ai'] ?? false ),
			totalTokensIn: (int) ( $row['total_tokens_in'] ?? 0 ),
			totalTokensOut: (int) ( $row['total_tokens_out'] ?? 0 ),
			totalCost: (float) ( $row['total_cost'] ?? 0 ),
			startedAt: $this->time( $row['started_at'] ?? null ),
			pageTitle: isset( $row['page_title'] ) ? (string) $row['page_title'] : null,
			tags: $tags,
			starred: (bool) ( $row['starred'] ?? false ),
			notes: $notes,
			handoffUserId: isset( $row['handoff_user_id'] ) ? (int) $row['handoff_user_id'] : null,
			handoffAt: $this->time( $row['handoff_at'] ?? null ),
			rating: isset( $row['rating'] ) ? (int) $row['rating'] : null,
			sentiment: isset( $row['sentiment'] ) ? (string) $row['sentiment'] : null,
			lastMessageAt: $this->time( $row['last_message_at'] ?? null ),
			endedAt: $this->time( $row['ended_at'] ?? null ),
		);
	}
}
