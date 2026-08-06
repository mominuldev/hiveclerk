<?php
/**
 * A job that succeeds, and records what it saw.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Queue;

use Hiveclerk\Core\Queue\AbstractJob;

/**
 * Reports the state of the heartbeat at the moment it ran.
 *
 * That is the whole point of it: the runner records a heartbeat *before*
 * calling `handle()`, so that a job killed by the memory or execution
 * limit still leaves evidence it was reached. Asserting the record exists
 * afterwards cannot tell that apart from recording it after the work —
 * the job itself has to look, while it is running.
 */
final class SucceedingProbeJob extends AbstractJob {

	/**
	 * Whether handle() ran.
	 *
	 * @var bool
	 */
	public bool $ran = false;

	/**
	 * Arguments handle() was given.
	 *
	 * @var array<string, mixed>
	 */
	public array $received = array();

	/**
	 * Heartbeats already on record when handle() ran.
	 *
	 * @var int
	 */
	public int $beatsWhenHandleRan = 0;

	/**
	 * Hook name.
	 *
	 * @return string
	 */
	public static function hook(): string {
		return 'hiveclerk/jobs/probe_ok';
	}

	/**
	 * Record and return.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return void
	 */
	public function handle( array $args ): void {
		$this->ran      = true;
		$this->received = $args;

		$stored                   = get_option( 'hiveclerk_job_runs', array() );
		$this->beatsWhenHandleRan = is_array( $stored ) ? count( $stored ) : 0;
	}
}
