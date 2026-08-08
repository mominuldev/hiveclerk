<?php
/**
 * Workflow run repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Workflow\RunStatus;
use Hiveclerk\Domain\Workflow\SubjectType;
use Hiveclerk\Domain\Workflow\WorkflowRun;
use Hiveclerk\Domain\Workflow\WorkflowRunRepositoryInterface;

/**
 * Stores runs.
 *
 * `open_key` mirrors `subject_id` while a run is open and is nulled the
 * moment it finishes. The unique index over (workflow_id, open_key) is
 * therefore a re-entry guard the database enforces, and this class is the
 * only place that knows the trick — everywhere else reads and writes
 * {@see WorkflowRun::$subjectId} and never sees it.
 */
final class WorkflowRunRepository extends AbstractRepository implements WorkflowRunRepositoryInterface {

	protected function table(): string {
		return Schema::WORKFLOW_RUNS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'status', 'started_at', 'finished_at', 'resume_at' );
	}

	public function find( int $id ): ?WorkflowRun {
		$row = $this->fetchRow( 'id = %d', array( $id ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function due( string $now, int $limit ): array {
		return array_map(
			fn ( array $row ): WorkflowRun => $this->hydrate( $row ),
			$this->fetchAll(
				'status IN ( %s, %s ) AND ( resume_at IS NULL OR resume_at <= %s )',
				array( RunStatus::Pending->value, RunStatus::Waiting->value, $now ),
				'id',
				'ASC',
				$limit
			)
		);
	}

	public function countDue( string $now ): int {
		return $this->countWhere(
			'status IN ( %s, %s ) AND ( resume_at IS NULL OR resume_at <= %s )',
			array( RunStatus::Pending->value, RunStatus::Waiting->value, $now )
		);
	}

	public function forWorkflow( int $workflowId, Pagination $pagination, ?string $status = null ): array {
		$where  = 'workflow_id = %d';
		$params = array( $workflowId );

		if ( null !== $status && null !== RunStatus::tryFrom( $status ) ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}

		return array_map(
			fn ( array $row ): WorkflowRun => $this->hydrate( $row ),
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

	public function countsByStatus( int $workflowId ): array {
		$table = $this->tableName();

		$sql = $this->db->prepare(
			"SELECT status, COUNT(*) AS total FROM `{$table}` WHERE workflow_id = %d GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$workflowId
		);

		if ( ! is_string( $sql ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ (string) ( $row['status'] ?? '' ) ] = (int) ( $row['total'] ?? 0 );
		}

		return $counts;
	}

	public function hasRun( int $workflowId, ?int $subjectId ): bool {
		if ( null === $subjectId ) {
			return false;
		}

		return $this->countWhere(
			'workflow_id = %d AND subject_id = %d',
			array( $workflowId, $subjectId )
		) > 0;
	}

	public function hasOpenRun( int $workflowId, ?int $subjectId ): bool {
		if ( null === $subjectId ) {
			return false;
		}

		return $this->countWhere(
			'workflow_id = %d AND open_key = %d',
			array( $workflowId, $subjectId )
		) > 0;
	}

	public function save( WorkflowRun $run ): WorkflowRun {
		$data = array(
			'workflow_id'  => $run->workflowId,
			'subject_type' => $run->subjectType->value,
			'subject_id'   => $run->subjectId,
			'open_key'     => $run->status->isOpen() ? $run->subjectId : null,
			'status'       => $run->status->value,
			'current_node' => $run->currentNode,
			'resume_at'    => $this->stamp( $run->resumeAt ),
			'attempts'     => $run->attempts,
			'steps'        => $run->steps,
			'context'      => $this->encodeJson( $run->context ),
			'error'        => $run->error,
			'finished_at'  => $this->stamp( $run->finishedAt ),
			'updated_at'   => $this->now(),
		);

		if ( null === $run->id ) {
			$data['started_at'] = $this->stamp( $run->startedAt ) ?? $this->now();

			// A null id back means the unique index refused a second open
			// run for this subject. That is the guard working, not an
			// error, and the caller reads the null exactly that way.
			$id = $this->insertRow( $data );

			if ( null === $id ) {
				return $run;
			}

			$run->id = $id;

			return $run;
		}

		$this->updateRow( $run->id, $data );

		return $run;
	}

	public function cancelOpen( int $workflowId, string $reason ): int {
		$table = $this->tableName();

		$sql = $this->db->prepare(
			"UPDATE `{$table}` SET status = %s, error = %s, open_key = NULL, resume_at = NULL," // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. ' finished_at = %s, updated_at = %s WHERE workflow_id = %d AND status IN ( %s, %s )',
			RunStatus::Cancelled->value,
			$reason,
			$this->now(),
			$this->now(),
			$workflowId,
			RunStatus::Pending->value,
			RunStatus::Waiting->value
		);

		if ( ! is_string( $sql ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->query( $sql );
	}

	public function pruneFinished( string $cutoff, int $limit ): array {
		$rows = $this->fetchAll(
			'status IN ( %s, %s, %s ) AND finished_at IS NOT NULL AND finished_at < %s',
			array(
				RunStatus::Completed->value,
				RunStatus::Failed->value,
				RunStatus::Cancelled->value,
				$cutoff,
			),
			'id',
			'ASC',
			$limit
		);

		$ids = array();

		foreach ( $rows as $row ) {
			$ids[] = (int) $row['id'];
		}

		if ( array() === $ids ) {
			return array();
		}

		$table        = $this->tableName();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// The ids came from this table's own primary key a line ago, so the
		// placeholders are belt and braces — but the rule in this codebase
		// is that every value reaches SQL through prepare(), and a rule
		// with an exception is a rule nobody can check by reading.
		$this->execute(
			"DELETE FROM `{$table}` WHERE id IN ( {$placeholders} )",
			$ids
		);

		return $ids;
	}

	/**
	 * Build a WorkflowRun from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return WorkflowRun
	 */
	private function hydrate( array $row ): WorkflowRun {
		return new WorkflowRun(
			id: (int) $row['id'],
			workflowId: (int) $row['workflow_id'],
			subjectType: SubjectType::fromStorage( $this->text( $row['subject_type'] ?? null ) ),
			subjectId: $this->intOrNull( $row['subject_id'] ?? null ),
			status: RunStatus::fromStorage( $this->text( $row['status'] ?? null ) ),
			currentNode: $this->text( $row['current_node'] ?? null ),
			resumeAt: $this->time( $row['resume_at'] ?? null ),
			attempts: (int) ( $row['attempts'] ?? 0 ),
			steps: (int) ( $row['steps'] ?? 0 ),
			context: $this->json( $row['context'] ?? null ),
			error: $this->text( $row['error'] ?? null ),
			startedAt: $this->time( $row['started_at'] ?? null ),
			updatedAt: $this->time( $row['updated_at'] ?? null ),
			finishedAt: $this->time( $row['finished_at'] ?? null ),
		);
	}
}
