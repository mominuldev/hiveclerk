<?php
/**
 * Webhook delivery job.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Jobs;

use Hiveclerk\Core\Queue\AbstractJob;
use Hiveclerk\Modules\Integrations\Services\WebhookDispatcher;

/**
 * One outbound event delivery (D9 §4).
 *
 * The payload does ride in the job arguments here, unlike the lead push,
 * and the difference is deliberate: an event describes a moment. A
 * `lead.stage_changed` delivered eleven hours late must say which stage
 * it moved to *then*, not which column the card sits in now. Re-reading
 * the lead would produce a webhook that quietly contradicts the event it
 * claims to be.
 */
final class WebhookDeliveryJob extends AbstractJob {

	/**
	 * Construct.
	 *
	 * @param WebhookDispatcher $dispatcher Delivery.
	 */
	public function __construct( private readonly WebhookDispatcher $dispatcher ) {
	}

	/**
	 * The hook this job runs on.
	 *
	 * @return string
	 */
	public static function hook(): string {
		return 'hiveclerk/jobs/webhook_delivery';
	}

	/**
	 * Deliver one event.
	 *
	 * @param array<string, mixed> $args Job arguments.
	 * @return void
	 */
	public function handle( array $args ): void {
		$integrationId = self::intArg( $args, 'integration_id' );
		$event         = self::stringArg( $args, 'event' );
		$attempt       = max( 1, self::intArg( $args, 'attempt', 1 ) );

		if ( $integrationId <= 0 || '' === $event ) {
			return;
		}

		$payload = array();

		foreach ( self::arrayArg( $args, 'payload' ) as $key => $value ) {
			if ( is_string( $key ) ) {
				$payload[ $key ] = $value;
			}
		}

		$this->dispatcher->deliver( $integrationId, $event, $payload, $attempt );
	}
}
