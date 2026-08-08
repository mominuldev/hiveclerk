<?php
/**
 * Runs without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Workflows;

use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Workflow\RunStatus;
use Hiveclerk\Domain\Workflow\WorkflowRun;
use Hiveclerk\Domain\Workflow\WorkflowRunRepositoryInterface;

/**
 * Runs, in memory.
 *
 * The unique index that makes re-entry impossible in production is
 * modelled here as an explicit check, because a fake that quietly allowed
 * two open runs would make the guard's test pass against a repository
 * that does not behave like the real one.
 *
 * @internal
 */
final class InMemoryRuns implements WorkflowRunRepositoryInterface {

	/**
	 * Stored runs by id.
	 *
	 * @var array<int, WorkflowRun>
	 */
	public array $rows = array();

	private int $nextId = 1;

	public function find( int $id ): ?WorkflowRun {
		return $this->rows[ $id ] ?? null;
	}

	public function due( string $now, int $limit ): array {
		$due = array_filter(
			$this->rows,
			static fn ( WorkflowRun $run ): bool => $run->status->isOpen()
				&& ( null === $run->resumeAt || $run->resumeAt->format( 'Y-m-d H:i:s' ) <= $now )
		);

		return array_slice( array_values( $due ), 0, $limit );
	}

	public function countDue( string $now ): int {
		return count( $this->due( $now, PHP_INT_MAX ) );
	}

	public function forWorkflow( int $workflowId, Pagination $pagination, ?string $status = null ): array {
		unset( $pagination );

		return array_values(
			array_filter(
				$this->rows,
				static fn ( WorkflowRun $run ): bool => $run->workflowId === $workflowId
					&& ( null === $status || $run->status->value === $status )
			)
		);
	}

	public function countsByStatus( int $workflowId ): array {
		$counts = array();

		foreach ( $this->rows as $run ) {
			if ( $run->workflowId !== $workflowId ) {
				continue;
			}

			$counts[ $run->status->value ] = ( $counts[ $run->status->value ] ?? 0 ) + 1;
		}

		return $counts;
	}

	public function hasRun( int $workflowId, ?int $subjectId ): bool {
		foreach ( $this->rows as $run ) {
			if ( $run->workflowId === $workflowId && $run->subjectId === $subjectId ) {
				return true;
			}
		}

		return false;
	}

	public function hasOpenRun( int $workflowId, ?int $subjectId ): bool {
		foreach ( $this->rows as $run ) {
			if (
				$run->workflowId === $workflowId
				&& $run->subjectId === $subjectId
				&& $run->status->isOpen()
			) {
				return true;
			}
		}

		return false;
	}

	public function save( WorkflowRun $run ): WorkflowRun {
		if ( null === $run->id ) {
			if ( $this->hasOpenRun( $run->workflowId, $run->subjectId ) ) {
				// What the unique index does in production: refuse, and
				// leave the caller holding a run with no id.
				return $run;
			}

			$run->id = $this->nextId++;
		}

		$this->rows[ $run->id ] = $run;

		return $run;
	}

	public function cancelOpen( int $workflowId, string $reason ): int {
		$cancelled = 0;

		foreach ( $this->rows as $run ) {
			if ( $run->workflowId === $workflowId && $run->status->isOpen() ) {
				$run->cancel( $reason );
				++$cancelled;
			}
		}

		return $cancelled;
	}

	public function pruneFinished( string $cutoff, int $limit ): array {
		$deleted = array();

		foreach ( $this->rows as $id => $run ) {
			if ( count( $deleted ) >= $limit ) {
				break;
			}

			if (
				RunStatus::Completed === $run->status
				&& null !== $run->finishedAt
				&& $run->finishedAt->format( 'Y-m-d H:i:s' ) < $cutoff
			) {
				$deleted[] = $id;
				unset( $this->rows[ $id ] );
			}
		}

		return $deleted;
	}
}
