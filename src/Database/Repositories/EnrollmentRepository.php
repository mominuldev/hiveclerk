<?php
/**
 * Enrolment repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Email\Enrollment;
use Hiveclerk\Domain\Email\EnrollmentRepositoryInterface;
use Hiveclerk\Domain\Email\EnrollmentStatus;

/**
 * Stores where each lead is inside each sequence.
 */
final class EnrollmentRepository extends AbstractRepository implements EnrollmentRepositoryInterface {

	protected function table(): string {
		return Schema::SEQUENCE_ENROLLMENTS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'next_send_at', 'enrolled_at' );
	}

	public function find( int $id ): ?Enrollment {
		$row = $this->fetchRow( 'id = %d', array( $id ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function findFor( int $sequenceId, int $leadId ): ?Enrollment {
		$row = $this->fetchRow(
			'sequence_id = %d AND lead_id = %d',
			array( $sequenceId, $leadId )
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	public function due( string $now, int $limit ): array {
		return array_map(
			fn ( array $row ): Enrollment => $this->hydrate( $row ),
			$this->fetchAll(
				'status = %s AND next_send_at IS NOT NULL AND next_send_at <= %s',
				array( EnrollmentStatus::Active->value, $now ),
				// Oldest due first. A backlog that drained newest-first
				// would leave the most overdue follow-up permanently at the
				// back of the queue.
				'next_send_at',
				'ASC',
				$limit
			)
		);
	}

	public function countDue( string $now ): int {
		return $this->countWhere(
			'status = %s AND next_send_at IS NOT NULL AND next_send_at <= %s',
			array( EnrollmentStatus::Active->value, $now )
		);
	}

	public function openForLead( int $leadId ): array {
		return array_map(
			fn ( array $row ): Enrollment => $this->hydrate( $row ),
			$this->fetchAll(
				'lead_id = %d AND status = %s',
				array( $leadId, EnrollmentStatus::Active->value ),
				'id',
				'ASC'
			)
		);
	}

	public function openForSequence( int $sequenceId, int $limit = 500 ): array {
		return array_map(
			fn ( array $row ): Enrollment => $this->hydrate( $row ),
			$this->fetchAll(
				'sequence_id = %d AND status = %s',
				array( $sequenceId, EnrollmentStatus::Active->value ),
				'id',
				'ASC',
				$limit
			)
		);
	}

	public function save( Enrollment $enrollment ): Enrollment {
		$data = array(
			'sequence_id'  => $enrollment->sequenceId,
			'lead_id'      => $enrollment->leadId,
			'status'       => $enrollment->status->value,
			'current_step' => $enrollment->currentStep,
			'next_send_at' => $this->stamp( $enrollment->nextSendAt ),
			'exit_reason'  => $enrollment->exitReason,
			'completed_at' => $this->stamp( $enrollment->completedAt ),
		);

		if ( null === $enrollment->id ) {
			$data['enrolled_at'] = null === $enrollment->enrolledAt
				? $this->now()
				: $this->stamp( $enrollment->enrolledAt );

			$id = $this->insertRow( $data );

			if ( null === $id ) {
				return $enrollment;
			}

			$enrollment->id = $id;

			return $enrollment;
		}

		$this->updateRow( $enrollment->id, $data );

		return $enrollment;
	}

	public function statusCounts( int $sequenceId ): array {
		$table = $this->tableName();

		$sql = $this->db->prepare(
			"SELECT status, COUNT(*) AS total FROM `{$table}` WHERE sequence_id = %d GROUP BY status",
			$sequenceId
		);

		if ( ! is_string( $sql ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $sql, ARRAY_A );

		$counts = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$status = $this->text( $row['status'] ?? null );

			if ( null !== $status ) {
				$counts[ $status ] = (int) ( $row['total'] ?? 0 );
			}
		}

		return $counts;
	}

	/**
	 * Build an Enrollment from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return Enrollment
	 */
	private function hydrate( array $row ): Enrollment {
		return new Enrollment(
			id: (int) $row['id'],
			sequenceId: (int) $row['sequence_id'],
			leadId: (int) $row['lead_id'],
			status: EnrollmentStatus::fromStorage( $this->text( $row['status'] ?? null ) ),
			currentStep: (int) ( $row['current_step'] ?? 0 ),
			nextSendAt: $this->time( $row['next_send_at'] ?? null ),
			exitReason: $this->text( $row['exit_reason'] ?? null ),
			enrolledAt: $this->time( $row['enrolled_at'] ?? null ),
			completedAt: $this->time( $row['completed_at'] ?? null ),
		);
	}
}
