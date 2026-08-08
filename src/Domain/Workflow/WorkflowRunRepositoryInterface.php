<?php
/**
 * Run storage port.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

use Hiveclerk\Domain\Shared\Pagination;

/**
 * Reads and writes runs.
 */
interface WorkflowRunRepositoryInterface {

	/**
	 * Find by storage id.
	 *
	 * @param int $id Storage id.
	 * @return WorkflowRun|null
	 */
	public function find( int $id ): ?WorkflowRun;

	/**
	 * Runs whose resume time has passed, oldest first.
	 *
	 * @param string $now   Current UTC time as a MySQL DATETIME.
	 * @param int    $limit Batch size.
	 * @return array<int, WorkflowRun>
	 */
	public function due( string $now, int $limit ): array;

	/**
	 * How many runs are due.
	 *
	 * @param string $now Current UTC time as a MySQL DATETIME.
	 * @return int
	 */
	public function countDue( string $now ): int;

	/**
	 * One page of a workflow's runs, newest first.
	 *
	 * @param int        $workflowId Workflow.
	 * @param Pagination $pagination Page request.
	 * @param string|null $status    Status filter.
	 * @return array<int, WorkflowRun>
	 */
	public function forWorkflow( int $workflowId, Pagination $pagination, ?string $status = null ): array;

	/**
	 * How many runs a workflow has, by status.
	 *
	 * @param int $workflowId Workflow.
	 * @return array<string, int>
	 */
	public function countsByStatus( int $workflowId ): array;

	/**
	 * Whether this subject has ever been run through this workflow.
	 *
	 * @param int      $workflowId Workflow.
	 * @param int|null $subjectId  Subject.
	 * @return bool
	 */
	public function hasRun( int $workflowId, ?int $subjectId ): bool;

	/**
	 * Whether this subject has a run still open on this workflow.
	 *
	 * @param int      $workflowId Workflow.
	 * @param int|null $subjectId  Subject.
	 * @return bool
	 */
	public function hasOpenRun( int $workflowId, ?int $subjectId ): bool;

	/**
	 * Insert or update.
	 *
	 * @param WorkflowRun $run Run.
	 * @return WorkflowRun
	 */
	public function save( WorkflowRun $run ): WorkflowRun;

	/**
	 * Cancel every open run of a workflow.
	 *
	 * @param int    $workflowId Workflow.
	 * @param string $reason     Why.
	 * @return int Rows affected.
	 */
	public function cancelOpen( int $workflowId, string $reason ): int;

	/**
	 * Delete finished runs older than a cutoff.
	 *
	 * @param string $cutoff UTC DATETIME before which finished runs go.
	 * @param int    $limit  Most to delete in one call.
	 * @return array<int, int> Ids of the runs deleted.
	 */
	public function pruneFinished( string $cutoff, int $limit ): array;
}
