<?php
/**
 * Run log storage port.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * Appends and reads the per-node history of a run.
 */
interface RunLogRepositoryInterface {

	/**
	 * Append one line.
	 *
	 * @param RunLogEntry $entry Entry.
	 * @return void
	 */
	public function append( RunLogEntry $entry ): void;

	/**
	 * A run's history, oldest first.
	 *
	 * @param int $runId Run.
	 * @param int $limit Most to return.
	 * @return array<int, RunLogEntry>
	 */
	public function forRun( int $runId, int $limit = 200 ): array;

	/**
	 * Delete the history of runs that have been pruned.
	 *
	 * @param array<int, int> $runIds Runs.
	 * @return int Rows deleted.
	 */
	public function deleteForRuns( array $runIds ): int;
}
