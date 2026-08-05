<?php
/**
 * Email log repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Email\EmailLogEntry;
use Hiveclerk\Domain\Email\EmailLogRepositoryInterface;
use Hiveclerk\Domain\Email\SendStatus;
use Hiveclerk\Domain\Shared\Pagination;

/**
 * Stores what was sent.
 */
final class EmailLogRepository extends AbstractRepository implements EmailLogRepositoryInterface {

	protected function table(): string {
		return Schema::EMAIL_LOG;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'created_at', 'sent_at', 'status' );
	}

	public function append( EmailLogEntry $entry ): EmailLogEntry {
		$id = $this->insertRow(
			array(
				'enrollment_id' => $entry->enrollmentId,
				'step_id'       => $entry->stepId,
				'lead_id'       => $entry->leadId,
				'message_id'    => $entry->messageId,
				'to_email'      => $entry->toEmail,
				'subject'       => $entry->subject,
				'status'        => $entry->status->value,
				'error'         => $entry->error,
				'sent_at'       => $this->stamp( $entry->sentAt ),
				'created_at'    => null === $entry->createdAt ? $this->now() : $this->stamp( $entry->createdAt ),
			)
		);

		if ( null !== $id ) {
			$entry->id = $id;
		}

		return $entry;
	}

	public function paginate( Pagination $pagination, ?int $leadId = null, ?SendStatus $status = null ): array {
		[ $where, $params ] = $this->filter( $leadId, $status );

		return array_map(
			fn ( array $row ): EmailLogEntry => $this->hydrate( $row ),
			$this->fetchAll( $where, $params, 'id', 'DESC', $pagination->perPage, $pagination->offset() )
		);
	}

	public function countMatching( ?int $leadId = null, ?SendStatus $status = null ): int {
		[ $where, $params ] = $this->filter( $leadId, $status );

		return $this->countWhere( $where, $params );
	}

	public function sentSince( string $since ): int {
		return $this->countWhere(
			'status = %s AND sent_at IS NOT NULL AND sent_at >= %s',
			array( SendStatus::Sent->value, $since )
		);
	}

	public function alreadySent( int $enrollmentId, int $stepId ): bool {
		return $this->countWhere(
			'enrollment_id = %d AND step_id = %d AND status IN ( %s, %s )',
			array( $enrollmentId, $stepId, SendStatus::Sent->value, SendStatus::Suppressed->value )
		) > 0;
	}

	public function forEmail( string $email, int $limit, int $offset = 0 ): array {
		return array_map(
			fn ( array $row ): EmailLogEntry => $this->hydrate( $row ),
			$this->fetchAll( 'to_email = %s', array( $email ), 'id', 'DESC', max( 1, $limit ), max( 0, $offset ) )
		);
	}

	public function deleteForEmail( string $email ): int {
		$table = $this->tableName();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$done = $this->execute( "DELETE FROM `{$table}` WHERE to_email = %s", array( $email ) );

		return $done ? (int) $this->db->rows_affected : 0;
	}

	public function statsFor( int $sequenceId ): array {
		$log         = $this->tableName();
		$enrollments = Schema::table( Schema::SEQUENCE_ENROLLMENTS );

		$sql = $this->db->prepare(
			"SELECT l.status, COUNT(*) AS total
			 FROM `{$log}` l
			 INNER JOIN `{$enrollments}` e ON e.id = l.enrollment_id
			 WHERE e.sequence_id = %d
			 GROUP BY l.status",
			$sequenceId
		);

		if ( ! is_string( $sql ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $sql, ARRAY_A );

		$stats = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$status = $this->text( $row['status'] ?? null );

			if ( null !== $status ) {
				$stats[ $status ] = (int) ( $row['total'] ?? 0 );
			}
		}

		return $stats;
	}

	/**
	 * Build a WHERE clause and its parameters.
	 *
	 * @param int|null        $leadId Recipient filter.
	 * @param SendStatus|null $status Outcome filter.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function filter( ?int $leadId, ?SendStatus $status ): array {
		$where  = array( '1=1' );
		$params = array();

		if ( null !== $leadId ) {
			$where[]  = 'lead_id = %d';
			$params[] = $leadId;
		}

		if ( null !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status->value;
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Build an EmailLogEntry from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return EmailLogEntry
	 */
	private function hydrate( array $row ): EmailLogEntry {
		return new EmailLogEntry(
			id: (int) $row['id'],
			leadId: (int) $row['lead_id'],
			toEmail: (string) $row['to_email'],
			subject: (string) $row['subject'],
			status: SendStatus::fromStorage( $this->text( $row['status'] ?? null ) ),
			enrollmentId: $this->intOrNull( $row['enrollment_id'] ?? null ),
			stepId: $this->intOrNull( $row['step_id'] ?? null ),
			messageId: $this->text( $row['message_id'] ?? null ),
			error: $this->text( $row['error'] ?? null ),
			sentAt: $this->time( $row['sent_at'] ?? null ),
			openedAt: $this->time( $row['opened_at'] ?? null ),
			clickedAt: $this->time( $row['clicked_at'] ?? null ),
			createdAt: $this->time( $row['created_at'] ?? null ),
		);
	}
}
