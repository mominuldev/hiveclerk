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
			'message_count'    => $conversation->messageCount,
			'summary'          => $conversation->summary,
			'resolved_by_ai'   => $conversation->resolvedByAi ? 1 : 0,
			'total_tokens_in'  => $conversation->totalTokensIn,
			'total_tokens_out' => $conversation->totalTokensOut,
			'total_cost'       => $conversation->totalCost,
			'last_message_at'  => $this->now(),
		);

		if ( null === $conversation->id ) {
			$data['started_at'] = $this->now();

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
		$messages  = Schema::table( Schema::MESSAGES );
		$citations = Schema::table( Schema::MESSAGE_CITATIONS );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->db->query( 'START TRANSACTION' );

		$citationsDeleted = $this->execute(
			"DELETE c FROM `{$citations}` c
			 INNER JOIN `{$messages}` m ON m.id = c.message_id
			 WHERE m.conversation_id = %d",
			array( $id )
		);

		$messagesDeleted = $this->execute(
			"DELETE FROM `{$messages}` WHERE conversation_id = %d",
			array( $id )
		);

		$deleted = $citationsDeleted && $messagesDeleted && $this->deleteRow( $id );

		$this->db->query( $deleted ? 'COMMIT' : 'ROLLBACK' );
		// phpcs:enable

		return $deleted;
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
	 * Build a Conversation from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return Conversation
	 */
	private function hydrate( array $row ): Conversation {
		$startedAt = null;

		if ( isset( $row['started_at'] ) && is_string( $row['started_at'] ) ) {
			$startedAt = new DateTimeImmutable( $row['started_at'], new DateTimeZone( 'UTC' ) );
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
			startedAt: $startedAt,
		);
	}
}
