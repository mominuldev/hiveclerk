<?php
/**
 * Analytics rollup job.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Analytics\Jobs;

use Hiveclerk\Core\Queue\AbstractJob;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Modules\Analytics\Services\RollupService;

/**
 * Rolls a bounded batch of days forward, then decides whether to return.
 *
 * Two mechanisms, as with the sequence tick: the hourly schedule is the
 * heartbeat that keeps a live site's figures fresh, and the re-enqueue is
 * what turns a year of backfill into twenty minutes rather than a
 * fortnight of hourly increments.
 *
 * ## Why hourly rather than nightly
 *
 * D6 §12 gives analytics aggregates a one-hour TTL, and the dashboard is
 * the first screen a customer opens in the morning. A nightly job means
 * the figures a customer looks at during the working day are only ever as
 * fresh as last midnight, and today — the day they care about — is the
 * one the rollup never has.
 */
final class RollupJob extends AbstractJob {

	/**
	 * Seconds between scheduled runs.
	 */
	public const INTERVAL = 3600;

	/**
	 * Construct.
	 *
	 * @param RollupService  $rollup Rollup service.
	 * @param QueueInterface $queue  Background work.
	 */
	public function __construct(
		private readonly RollupService $rollup,
		private readonly QueueInterface $queue
	) {
	}

	/**
	 * The hook this job runs on.
	 *
	 * @return string
	 */
	public static function hook(): string {
		return 'hiveclerk/jobs/analytics_rollup';
	}

	/**
	 * Roll one batch.
	 *
	 * @param array<string, mixed> $args Job arguments.
	 * @return void
	 */
	public function handle( array $args ): void {
		unset( $args );

		$result = $this->rollup->run();

		if ( $result['remaining'] <= 0 ) {
			return;
		}

		// Only when the batch actually moved. A run that processed nothing
		// and still reports work outstanding is a run that will report the
		// same thing forever, and a queue that never drains looks exactly
		// like a queue that is not running.
		if ( $result['processed'] > 0 ) {
			$this->queue->enqueue( self::hook() );
		}
	}
}
