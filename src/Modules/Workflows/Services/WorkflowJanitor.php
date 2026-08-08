<?php
/**
 * Run retention.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Services;

use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Workflow\RunLogRepositoryInterface;
use Hiveclerk\Domain\Workflow\WorkflowRunRepositoryInterface;

/**
 * Deletes finished runs once they stop being evidence.
 *
 * A run row and its log are how an operator answers "why did this lead
 * get that email", so they outlive the run by a long way — but not for
 * ever. A busy site opens a run per lead per workflow, and a table that
 * only grows is a table that eventually makes the runs screen slow for
 * everybody to keep six-month-old evidence nobody has ever asked for.
 *
 * Ninety days, deleted in bounded batches from the tick, and only when
 * the tick had nothing more urgent to do.
 */
final class WorkflowJanitor {

	/**
	 * How long a finished run is kept.
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * Runs deleted per pass.
	 */
	public const BATCH = 100;

	/**
	 * Construct.
	 *
	 * @param WorkflowRunRepositoryInterface $runs  Run storage.
	 * @param RunLogRepositoryInterface      $log   Run log.
	 * @param ClockInterface                 $clock Clock.
	 */
	public function __construct(
		private readonly WorkflowRunRepositoryInterface $runs,
		private readonly RunLogRepositoryInterface $log,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Delete one batch of expired runs.
	 *
	 * @return int Runs deleted.
	 */
	public function prune(): int {
		$cutoff = $this->clock->now()
			->modify( '-' . self::RETENTION_DAYS . ' days' )
			->format( 'Y-m-d H:i:s' );

		$deleted = $this->runs->pruneFinished( $cutoff, self::BATCH );

		if ( array() === $deleted ) {
			return 0;
		}

		// Log rows go after the runs they belong to, never before. The
		// other order leaves a window where a run is on screen with its
		// history already gone, and "no steps recorded" on a completed run
		// reads as a bug in the engine.
		$this->log->deleteForRuns( $deleted );

		return count( $deleted );
	}
}
