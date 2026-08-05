<?php
/**
 * Job registration and dispatch.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Queue;

use Throwable;

/**
 * Binds jobs to their hooks and runs them safely.
 *
 * Both queue drivers ultimately fire a WordPress action, so a single
 * listener per job is enough for either. What this adds is the guarantee
 * that a job throwing does not take down the request that happened to
 * trigger the queue — on WP-Cron that request belongs to a visitor, and a
 * fatal in a background job would render a white screen on a page that
 * has nothing to do with it.
 */
final class JobRegistry {

	/**
	 * Jobs by hook.
	 *
	 * @var array<string, JobInterface>
	 */
	private array $jobs = array();

	/**
	 * Register a job.
	 *
	 * @param JobInterface $job Job.
	 * @return void
	 */
	public function add( JobInterface $job ): void {
		$this->jobs[ $job::hook() ] = $job;
	}

	/**
	 * Attach every job to its hook.
	 *
	 * @return void
	 */
	public function boot(): void {
		foreach ( $this->jobs as $hook => $job ) {
			add_action(
				$hook,
				function ( mixed $args = array() ) use ( $job, $hook ): void {
					$this->run( $job, $hook, is_array( $args ) ? $args : array() );
				},
				10,
				1
			);
		}
	}

	/**
	 * Registered hook names.
	 *
	 * @return array<int, string>
	 */
	public function hooks(): array {
		return array_keys( $this->jobs );
	}

	/**
	 * Run one job, containing any failure.
	 *
	 * @param JobInterface         $job  Job.
	 * @param string               $hook Hook name, for the log line.
	 * @param array<string, mixed> $args Arguments.
	 * @return void
	 */
	private function run( JobInterface $job, string $hook, array $args ): void {
		/*
		 * Recorded before the work, not after. A job that fatals — exhausts
		 * memory, hits the execution limit — never reaches a line after
		 * `handle()`, and those are exactly the jobs an operator most needs
		 * to see evidence of. Recording the attempt means the status screen
		 * can say "it is being called and it is dying", which is a different
		 * and much more actionable answer than "nothing is calling it".
		 */
		JobHeartbeat::record( $hook, time() );

		try {
			$job->handle( $args );
		} catch ( Throwable $e ) {
			JobHeartbeat::record( $hook, time(), true );

			// Logged rather than rethrown. Action Scheduler would mark the
			// action failed and stop retrying, which is the right outcome
			// for a job whose arguments are permanently unusable; on
			// WP-Cron an uncaught throw would surface on a visitor's page.
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'Hiveclerk job %s failed: %s in %s:%d',
					$hook,
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				)
			);

			/**
			 * Fires when a background job throws.
			 *
			 * @param string               $hook Hook name.
			 * @param Throwable            $e    The failure.
			 * @param array<string, mixed> $args Arguments it was given.
			 */
			do_action( 'hiveclerk/job/failed', $hook, $e, $args );
		}
	}
}
