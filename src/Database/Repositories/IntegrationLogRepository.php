<?php
/**
 * Sync log repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Integration\SyncLogEntry;
use Hiveclerk\Domain\Integration\SyncLogRepositoryInterface;
use Hiveclerk\Domain\Integration\SyncStatus;
use Hiveclerk\Domain\Shared\Pagination;

/**
 * Stores sync attempts.
 */
final class IntegrationLogRepository extends AbstractRepository implements SyncLogRepositoryInterface {

	protected function table(): string {
		return Schema::INTEGRATION_LOG;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'created_at', 'status' );
	}

	public function append( SyncLogEntry $entry ): SyncLogEntry {
		$id = $this->insertRow(
			array(
				'integration_id'  => $entry->integrationId,
				'lead_id'         => $entry->leadId,
				'operation'       => $entry->operation,
				'status'          => $entry->status->value,
				'attempt'         => $entry->attempt,
				'external_id'     => $entry->externalId,
				'request_summary' => $this->encodeJson( $entry->requestSummary ),
				'response_code'   => $entry->responseCode,
				'error'           => $entry->error,
				'next_retry_at'   => $this->stamp( $entry->nextRetryAt ),
				'created_at'      => null === $entry->createdAt ? $this->now() : $this->stamp( $entry->createdAt ),
			)
		);

		if ( null !== $id ) {
			$entry->id = $id;
		}

		return $entry;
	}

	public function paginate( Pagination $pagination, ?int $integrationId = null, ?SyncStatus $status = null ): array {
		[ $where, $params ] = $this->filter( $integrationId, $status );

		return array_map(
			fn ( array $row ): SyncLogEntry => $this->hydrate( $row ),
			$this->fetchAll(
				$where,
				$params,
				'id',
				'DESC',
				$pagination->perPage,
				$pagination->offset()
			)
		);
	}

	public function countMatching( ?int $integrationId = null, ?SyncStatus $status = null ): int {
		[ $where, $params ] = $this->filter( $integrationId, $status );

		return $this->countWhere( $where, $params );
	}

	public function lastSuccess( int $integrationId, int $leadId ): ?SyncLogEntry {
		$rows = $this->fetchAll(
			'integration_id = %d AND lead_id = %d AND status = %s AND external_id IS NOT NULL',
			array( $integrationId, $leadId, SyncStatus::Success->value ),
			'id',
			'DESC',
			1
		);

		return array() === $rows ? null : $this->hydrate( $rows[0] );
	}

	public function contactsSynced( int $integrationId ): int {
		$table = $this->tableName();

		$sql = $this->db->prepare(
			"SELECT COUNT(DISTINCT lead_id) FROM `{$table}`
			 WHERE integration_id = %d AND status = %s AND lead_id IS NOT NULL",
			$integrationId,
			SyncStatus::Success->value
		);

		if ( ! is_string( $sql ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->get_var( $sql );
	}

	public function recentFailures( int $integrationId ): int {
		$table = $this->tableName();

		// Failures since the last success, not failures in total. A
		// connection that broke last March and has worked ever since is
		// working, and a card that counts its whole history says otherwise
		// for as long as the log is retained.
		$sql = $this->db->prepare(
			"SELECT COUNT(*) FROM `{$table}`
			 WHERE integration_id = %d
			   AND status = %s
			   AND id > COALESCE(
			       ( SELECT MAX(id) FROM `{$table}` WHERE integration_id = %d AND status = %s ),
			       0
			   )",
			$integrationId,
			SyncStatus::Failed->value,
			$integrationId,
			SyncStatus::Success->value
		);

		if ( ! is_string( $sql ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->get_var( $sql );
	}

	public function purge( int $integrationId ): int {
		$table = $this->tableName();

		$sql = $this->db->prepare( "DELETE FROM `{$table}` WHERE integration_id = %d", $integrationId );

		if ( ! is_string( $sql ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->query( $sql );
	}

	/**
	 * Build a WHERE clause and its parameters.
	 *
	 * @param int|null        $integrationId Connection filter.
	 * @param SyncStatus|null $status        Outcome filter.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function filter( ?int $integrationId, ?SyncStatus $status ): array {
		$where  = array( '1=1' );
		$params = array();

		if ( null !== $integrationId ) {
			$where[]  = 'integration_id = %d';
			$params[] = $integrationId;
		}

		if ( null !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status->value;
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Build a SyncLogEntry from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return SyncLogEntry
	 */
	private function hydrate( array $row ): SyncLogEntry {
		return new SyncLogEntry(
			id: (int) $row['id'],
			integrationId: (int) $row['integration_id'],
			operation: (string) $row['operation'],
			status: SyncStatus::fromStorage( $this->text( $row['status'] ?? null ) ),
			leadId: $this->intOrNull( $row['lead_id'] ?? null ),
			attempt: (int) ( $row['attempt'] ?? 1 ),
			externalId: $this->text( $row['external_id'] ?? null ),
			requestSummary: $this->json( $row['request_summary'] ?? null ),
			responseCode: $this->intOrNull( $row['response_code'] ?? null ),
			error: $this->text( $row['error'] ?? null ),
			nextRetryAt: $this->time( $row['next_retry_at'] ?? null ),
			createdAt: $this->time( $row['created_at'] ?? null ),
		);
	}
}
