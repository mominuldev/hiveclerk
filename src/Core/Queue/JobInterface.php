<?php
/**
 * Background job contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Queue;

/**
 * One unit of background work.
 *
 * A job declares its own hook name so nothing outside it has to know the
 * string. Registration, enqueueing and cancellation all read it from the
 * same place, which removes the class of bug where work is scheduled onto
 * a hook nothing is listening on and simply never happens.
 */
interface JobInterface {

	/**
	 * The hook this job runs on.
	 *
	 * @return string
	 */
	public static function hook(): string;

	/**
	 * Do the work.
	 *
	 * Throwing is allowed and meaningful: the runner logs it and lets the
	 * driver decide about a retry. Swallowing errors here would make a
	 * failing job indistinguishable from a successful one.
	 *
	 * @param array<string, mixed> $args Arguments the job was queued with.
	 * @return void
	 */
	public function handle( array $args ): void;
}
