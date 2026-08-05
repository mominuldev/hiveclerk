<?php
/**
 * Sequence step repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Email\SequenceStep;
use Hiveclerk\Domain\Email\SequenceStepRepositoryInterface;

/**
 * Stores the emails inside a sequence.
 */
final class SequenceStepRepository extends AbstractRepository implements SequenceStepRepositoryInterface {

	protected function table(): string {
		return Schema::SEQUENCE_STEPS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'position' );
	}

	public function find( int $id ): ?SequenceStep {
		$row = $this->fetchRow( 'id = %d', array( $id ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function forSequence( int $sequenceId ): array {
		$table = $this->tableName();

		// Position then id, because two steps can share a position after a
		// reorder that half-applied and an email sequence whose order
		// changes between two page loads is worse than one that is wrong.
		$sql = $this->db->prepare(
			"SELECT * FROM `{$table}` WHERE sequence_id = %d ORDER BY position ASC, id ASC",
			$sequenceId
		);

		if ( ! is_string( $sql ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $sql, ARRAY_A );

		return array_map(
			fn ( array $row ): SequenceStep => $this->hydrate( $row ),
			is_array( $rows ) ? $rows : array()
		);
	}

	public function atPosition( int $sequenceId, int $position ): ?SequenceStep {
		$row = $this->fetchRow(
			'sequence_id = %d AND position = %d',
			array( $sequenceId, $position )
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	public function save( SequenceStep $step ): SequenceStep {
		$data = array(
			'sequence_id'   => $step->sequenceId,
			'position'      => $step->position,
			'delay_minutes' => $step->delayMinutes,
			'subject'       => $step->subject,
			'body_html'     => $step->bodyHtml,
			'body_text'     => $step->bodyText,
			'ai_generated'  => $step->aiGenerated ? 1 : 0,
			'approved_by'   => $step->approvedBy,
			'approved_at'   => $this->stamp( $step->approvedAt ),
			'conditions'    => $this->encodeJson( $step->conditions ),
			'updated_at'    => $this->now(),
		);

		if ( null === $step->id ) {
			$data['created_at'] = $this->now();

			$id = $this->insertRow( $data );

			if ( null === $id ) {
				return $step;
			}

			$step->id = $id;

			return $step;
		}

		$this->updateRow( $step->id, $data );

		return $step;
	}

	public function delete( int $id ): bool {
		return $this->deleteRow( $id );
	}

	public function reorder( array $ids ): void {
		$position = 0;

		foreach ( $ids as $id ) {
			$this->updateRow( (int) $id, array( 'position' => $position ) );

			++$position;
		}
	}

	public function countFor( int $sequenceId ): int {
		return $this->countWhere( 'sequence_id = %d', array( $sequenceId ) );
	}

	/**
	 * Build a SequenceStep from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return SequenceStep
	 */
	private function hydrate( array $row ): SequenceStep {
		return new SequenceStep(
			id: (int) $row['id'],
			sequenceId: (int) $row['sequence_id'],
			position: (int) ( $row['position'] ?? 0 ),
			delayMinutes: (int) ( $row['delay_minutes'] ?? 0 ),
			subject: (string) ( $row['subject'] ?? '' ),
			bodyHtml: (string) ( $row['body_html'] ?? '' ),
			bodyText: $this->text( $row['body_text'] ?? null ),
			aiGenerated: (bool) ( $row['ai_generated'] ?? false ),
			approvedBy: $this->intOrNull( $row['approved_by'] ?? null ),
			approvedAt: $this->time( $row['approved_at'] ?? null ),
			conditions: $this->json( $row['conditions'] ?? null ),
			createdAt: $this->time( $row['created_at'] ?? null ),
			updatedAt: $this->time( $row['updated_at'] ?? null ),
		);
	}
}
