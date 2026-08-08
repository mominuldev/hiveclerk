<?php
/**
 * Workflow tick job.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Jobs;

use Hiveclerk\Core\Queue\AbstractJob;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Modules\Workflows\Services\TriggerRouter;
use Hiveclerk\Modules\Workflows\Services\WorkflowEngine;
use Hiveclerk\Modules\Workflows\Services\WorkflowJanitor;

/**
 * Sweeps schedules, advances runs, then decides whether to come back.
 *
 * Three jobs would have been three cron entries on a site that may be
 * using none of this. One recurring entry every five minutes does all
 * three, and the two it does not need cost an indexed query each.
 *
 * Re-enqueues itself only when the pass actually moved something, which
 * is the same rule the sequence tick follows and for the same reason: a
 * backlog blocked on a paused workflow would otherwise re-enqueue for
 * ever, and a queue that never empties is indistinguishable from a queue
 * that is not running.
 */
final class WorkflowTickJob extends AbstractJob {

	/**
	 * Seconds between scheduled runs.
	 *
	 * Five minutes. Workflow delays are measured in hours and days, so
	 * the imprecision is invisible; a cron entry firing twelve times an
	 * hour on a site with no workflows is a cost the customer pays for a
	 * feature they are not using.
	 */
	public const INTERVAL = 300;

	/**
	 * Construct.
	 *
	 * @param WorkflowEngine  $engine  Run execution.
	 * @param TriggerRouter   $router  Scheduled sweeps.
	 * @param WorkflowJanitor $janitor Run retention.
	 * @param QueueInterface  $queue   Background work.
	 */
	public function __construct(
		private readonly WorkflowEngine $engine,
		private readonly TriggerRouter $router,
		private readonly WorkflowJanitor $janitor,
		private readonly QueueInterface $queue
	) {
	}

	/**
	 * The hook this job runs on.
	 *
	 * @return string
	 */
	public static function hook(): string {
		return 'hiveclerk/jobs/workflow_tick';
	}

	/**
	 * Advance one batch.
	 *
	 * @param array<string, mixed> $args Job arguments.
	 * @return void
	 */
	public function handle( array $args ): void {
		unset( $args );

		// Schedules first: a sweep that opens runs wants them advanced in
		// the same pass rather than five minutes later.
		$this->router->sweepSchedules();

		$result = $this->engine->tick();

		// Pruning last and only when the batch was light. Retention is
		// never the reason a customer's automation waits.
		if ( $result['remaining'] <= 0 ) {
			$this->janitor->prune();

			return;
		}

		if ( $result['advanced'] > 0 ) {
			$this->queue->enqueue( self::hook() );
		}
	}
}
