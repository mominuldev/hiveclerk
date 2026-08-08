<?php
/**
 * Workflow run log repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Workflow\NodeOutcome;
use Hiveclerk\Domain\Workflow\NodeType;
use Hiveclerk\Domain\Workflow\RunLogEntry;
use Hiveclerk\Domain\Workflow\RunLogRepositoryInterface;

/**
 * Stores the per-node history of a run.
 */
final class WorkflowRunLogRepository extends AbstractRepository implements RunLogRepositoryInterface {

	/**
	 * Longest detail line kept.
	 *
	 * A provider error body can run to kilobytes and the value of it here
	 * is the first sentence. The column is TEXT, so this is about the
	 * screen that renders a hundred of these, not about storage.
	 */
	private const MAX_DETAIL = 500;

	protected function table(): string {
		return Schema::WORKFLOW_RUN_LOG;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'created_at' );
	}

	public function append( RunLogEntry $entry ): void {
		$this->insertRow(
			array(
				'run_id'     => $entry->runId,
				'node_id'    => $entry->nodeId,
				'node_type'  => $entry->nodeType->value,
				'outcome'    => $entry->outcome->value,
				'detail'     => null === $entry->detail
					? null
					: mb_substr( $entry->detail, 0, self::MAX_DETAIL ),
				'created_at' => $this->stamp( $entry->createdAt ) ?? $this->now(),
			)
		);
	}

	public function forRun( int $runId, int $limit = 200 ): array {
		return array_map(
			fn ( array $row ): RunLogEntry => $this->hydrate( $row ),
			$this->fetchAll( 'run_id = %d', array( $runId ), 'id', 'ASC', $limit )
		);
	}

	public function deleteForRuns( array $runIds ): int {
		$ids = array();

		foreach ( $runIds as $id ) {
			$ids[] = (int) $id;
		}

		if ( array() === $ids ) {
			return 0;
		}

		$table        = $this->tableName();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		return $this->execute(
			"DELETE FROM `{$table}` WHERE run_id IN ( {$placeholders} )",
			$ids
		) ? count( $ids ) : 0;
	}

	/**
	 * Build a RunLogEntry from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return RunLogEntry
	 */
	private function hydrate( array $row ): RunLogEntry {
		return new RunLogEntry(
			id: (int) $row['id'],
			runId: (int) $row['run_id'],
			nodeId: (string) $row['node_id'],
			nodeType: NodeType::tryFromStorage( $this->text( $row['node_type'] ?? null ) ) ?? NodeType::Action,
			outcome: NodeOutcome::fromStorage( $this->text( $row['outcome'] ?? null ) ),
			detail: $this->text( $row['detail'] ?? null ),
			createdAt: $this->time( $row['created_at'] ?? null ),
		);
	}
}
