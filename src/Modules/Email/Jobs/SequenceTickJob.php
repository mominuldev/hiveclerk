<?php
/**
 * Sequence tick job.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Email\Jobs;

use Hiveclerk\Core\Queue\AbstractJob;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Modules\Email\Services\SequenceEngine;

/**
 * Sends whatever is due, then decides whether to come straight back.
 *
 * Registered as a recurring job every five minutes, and it re-enqueues
 * itself immediately when the batch it just ran did not clear the
 * backlog. Those are two different mechanisms doing two different things:
 * the recurring schedule is the heartbeat that finds newly due work, and
 * the re-enqueue is what drains two thousand overdue enrolments in twenty
 * minutes rather than in three hours of five-minute increments.
 *
 * ## Why five minutes rather than one
 *
 * Delays in this product are measured in hours and days. A minute of
 * imprecision on a two-day wait is invisible; a cron entry firing twelve
 * times an hour on a site with no sequences at all is a cost the customer
 * pays for a feature they are not using.
 */
final class SequenceTickJob extends AbstractJob {

	/**
	 * Seconds between scheduled runs.
	 */
	public const INTERVAL = 300;

	/**
	 * Construct.
	 *
	 * @param SequenceEngine $engine Sequence engine.
	 * @param QueueInterface $queue  Background work.
	 */
	public function __construct(
		private readonly SequenceEngine $engine,
		private readonly QueueInterface $queue
	) {
	}

	/**
	 * The hook this job runs on.
	 *
	 * @return string
	 */
	public static function hook(): string {
		return 'hiveclerk/jobs/sequence_tick';
	}

	/**
	 * Advance one batch.
	 *
	 * @param array<string, mixed> $args Job arguments.
	 * @return void
	 */
	public function handle( array $args ): void {
		unset( $args );

		$result = $this->engine->tick();

		if ( $result['remaining'] <= 0 ) {
			return;
		}

		// Only when something actually moved. A backlog that is stuck —
		// every enrolment blocked on an unapproved draft, or the hourly
		// ceiling reached — would otherwise re-enqueue forever, and a
		// queue that never empties is indistinguishable from a queue that
		// is not running.
		if ( $result['sent'] > 0 ) {
			$this->queue->enqueue( self::hook() );
		}
	}
}
