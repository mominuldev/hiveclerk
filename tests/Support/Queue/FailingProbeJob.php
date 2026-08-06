<?php
/**
 * A job that throws.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Queue;

use Hiveclerk\Core\Queue\AbstractJob;
use RuntimeException;

/**
 * Stands in for the ordinary case: a provider that was unreachable.
 *
 * On WP-Cron a job runs inside a visitor's page load, so what the runner
 * does with this exception decides whether a failed background task is an
 * operator's problem or a visitor's error page.
 */
final class FailingProbeJob extends AbstractJob {

	/**
	 * Hook name.
	 *
	 * @return string
	 */
	public static function hook(): string {
		return 'hiveclerk/jobs/probe_fail';
	}

	/**
	 * Always throws.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return void
	 *
	 * @throws RuntimeException Always.
	 */
	public function handle( array $args ): void {
		unset( $args );

		throw new RuntimeException( 'the provider was unreachable' );
	}
}
