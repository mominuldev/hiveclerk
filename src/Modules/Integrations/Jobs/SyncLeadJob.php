<?php
/**
 * Lead push job.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Jobs;

use Hiveclerk\Core\Queue\AbstractJob;
use Hiveclerk\Modules\Integrations\Services\SyncService;

/**
 * One push to one destination (FR-CRM-08).
 *
 * The whole job is three lookups and a call, and that is deliberate: the
 * retry decision, the log row and the backoff all live in the service, so
 * a re-queued attempt runs exactly the same code as the first one. A job
 * that reimplemented any of that would be a second retry policy that
 * drifts from the first.
 *
 * ## Why the arguments are ids and not objects
 *
 * A queued payload is serialised and may sit for twelve hours. A lead
 * serialised now and pushed then would carry the score it had when the
 * conversation ended rather than the one it has when the CRM finally
 * accepts it — and a serialised credential is exactly what
 * `ConnectorCredentials::__sleep()` exists to prevent.
 */
final class SyncLeadJob extends AbstractJob {

	/**
	 * Construct.
	 *
	 * @param SyncService $sync Synchronisation.
	 */
	public function __construct( private readonly SyncService $sync ) {
	}

	/**
	 * The hook this job runs on.
	 *
	 * @return string
	 */
	public static function hook(): string {
		return 'hiveclerk/jobs/sync_lead';
	}

	/**
	 * Push one lead.
	 *
	 * @param array<string, mixed> $args Job arguments.
	 * @return void
	 */
	public function handle( array $args ): void {
		$integrationId = self::intArg( $args, 'integration_id' );
		$leadId        = self::intArg( $args, 'lead_id' );
		$attempt       = max( 1, self::intArg( $args, 'attempt', 1 ) );

		if ( $integrationId <= 0 || $leadId <= 0 ) {
			return;
		}

		$integration = $this->sync->integration( $integrationId );
		$lead        = $this->sync->lead( $leadId );

		// Either can have been deleted between queueing and running — an
		// operator removing a duplicate lead, an integration disconnected
		// while the queue was backed up. Neither is an error worth
		// throwing over; there is simply nothing left to do.
		if ( null === $integration || null === $lead ) {
			return;
		}

		$this->sync->attempt( $integration, $lead, $attempt );
	}
}
