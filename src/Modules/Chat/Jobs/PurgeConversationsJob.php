<?php
/**
 * The nightly retention purge.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Jobs;

use Hiveclerk\Core\Queue\AbstractJob;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Modules\Chat\Services\RetentionService;

/**
 * Applies the retention policy, one bounded batch at a time (FR-CNV-07).
 *
 * The batch and the re-enqueue are the whole design. A site turning its
 * retention policy down from twelve months to three has a hundred
 * thousand conversations to delete in one night, and a job that tries to
 * do that in a single run either exceeds the host's execution limit —
 * leaving the deletion half-done and the schedule none the wiser — or
 * holds a PHP worker for minutes on a machine that has few of them.
 *
 * Re-enqueueing only while there is work left is what keeps the nightly
 * schedule honest: on a normal night this runs once, deletes a handful,
 * and stops.
 */
final class PurgeConversationsJob extends AbstractJob {

	/**
	 * How often the schedule fires, in seconds.
	 */
	public const INTERVAL = DAY_IN_SECONDS;

	/**
	 * Passes one invocation will chain before waiting for the next run.
	 *
	 * A backlog is drained over several nights rather than in one
	 * unbounded chain. Ten batches is a thousand conversations, which is
	 * a lot of deletion for one night on a shared host and still finite.
	 */
	private const MAX_PASSES = 10;

	/**
	 * Construct.
	 *
	 * @param RetentionService $retention Retention policy.
	 * @param QueueInterface   $queue     Background queue.
	 */
	public function __construct(
		private readonly RetentionService $retention,
		private readonly QueueInterface $queue
	) {
	}

	/**
	 * Hook name.
	 *
	 * @return string
	 */
	public static function hook(): string {
		return 'hiveclerk/job/purge_conversations';
	}

	/**
	 * Delete what the policy says should be gone.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return void
	 */
	public function handle( array $args ): void {
		$pass = self::intArg( $args, 'pass', 1 );

		$this->retention->purgeSessions();

		$deleted = $this->retention->purgeBatch();

		if ( 0 === $deleted || $pass >= self::MAX_PASSES ) {
			return;
		}

		// Only chains while a full batch came back, which is the signal
		// that there is more behind it.
		if ( $deleted < RetentionService::BATCH ) {
			return;
		}

		$this->queue->enqueue( self::hook(), array( 'pass' => $pass + 1 ) );
	}
}
