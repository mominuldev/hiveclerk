<?php
/**
 * Outbound webhook delivery.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Services;

use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Integration\IntegrationRepositoryInterface;
use Hiveclerk\Domain\Integration\SyncLogEntry;
use Hiveclerk\Domain\Integration\SyncLogRepositoryInterface;
use Hiveclerk\Domain\Integration\SyncStatus;
use Hiveclerk\Modules\Integrations\Jobs\WebhookDeliveryJob;
use Hiveclerk\Modules\Integrations\Support\ConnectorHttp;
use Hiveclerk\Modules\Integrations\Support\WebhookSigner;

/**
 * The eight events of D9 §4, delivered to a customer's endpoint.
 *
 * Separate from the webhook *connector*, which pushes contacts on the
 * sync trigger. This pushes events — a conversation started, a knowledge
 * gap appeared — which have no contact and no field mapping. Both use the
 * same stored URL and the same signing secret, so an operator configures
 * one endpoint and receives both.
 *
 * ## Events are opt-in per event, not all-or-nothing
 *
 * `conversation.started` fires on every conversation on the site. A
 * customer who wanted `lead.qualified` and got that instead has a
 * receiver taking thousands of requests a day and a bill to match. The
 * subscription list is stored on the integration and defaults to the two
 * lead events.
 */
final class WebhookDispatcher {

	public const PROVIDER = 'webhook';

	/**
	 * Events a customer can subscribe to (D9 §4).
	 *
	 * @var array<int, string>
	 */
	public const EVENTS = array(
		'conversation.started',
		'conversation.ended',
		'conversation.handoff_requested',
		'lead.captured',
		'lead.qualified',
		'lead.stage_changed',
		'knowledge.sync_completed',
		'knowledge.gap_detected',
	);

	/**
	 * What a site receives if it never chose.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_EVENTS = array( 'lead.captured', 'lead.qualified' );

	/**
	 * Construct.
	 *
	 * @param IntegrationRepositoryInterface $integrations Connection storage.
	 * @param SyncLogRepositoryInterface     $log          Attempt storage.
	 * @param CredentialStore                $credentials  Secret storage.
	 * @param ConnectorHttp                  $http         Outbound HTTP.
	 * @param WebhookSigner                  $signer       Signature builder.
	 * @param RetryPolicy                    $retries      Backoff schedule.
	 * @param QueueInterface                 $queue        Background work.
	 * @param ClockInterface                 $clock        Clock.
	 */
	public function __construct(
		private readonly IntegrationRepositoryInterface $integrations,
		private readonly SyncLogRepositoryInterface $log,
		private readonly CredentialStore $credentials,
		private readonly ConnectorHttp $http,
		private readonly WebhookSigner $signer,
		private readonly RetryPolicy $retries,
		private readonly QueueInterface $queue,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Queue one event if anybody subscribed to it.
	 *
	 * @param string               $event   Event name.
	 * @param array<string, mixed> $payload Event body.
	 * @return bool Whether anything was queued.
	 */
	public function dispatch( string $event, array $payload ): bool {
		if ( ! in_array( $event, self::EVENTS, true ) ) {
			return false;
		}

		$integration = $this->integrations->findByProvider( self::PROVIDER );

		if ( null === $integration || ! $integration->isUsable() || null === $integration->id ) {
			return false;
		}

		if ( ! in_array( $event, $this->subscriptions( $integration->syncConfig ), true ) ) {
			return false;
		}

		return $this->queue->enqueue(
			WebhookDeliveryJob::hook(),
			array(
				'integration_id' => $integration->id,
				'event'          => $event,
				'payload'        => $payload,
				'attempt'        => 1,
			)
		);
	}

	/**
	 * Deliver one event, retrying on the standard schedule.
	 *
	 * @param int                  $integrationId Connection id.
	 * @param string               $event         Event name.
	 * @param array<string, mixed> $payload       Event body.
	 * @param int                  $attempt       1-based attempt number.
	 * @return bool Whether the receiver accepted it.
	 */
	public function deliver( int $integrationId, string $event, array $payload, int $attempt ): bool {
		$integration = $this->integrations->find( $integrationId );

		if ( null === $integration || ! $integration->isUsable() ) {
			return false;
		}

		$credentials = $this->credentials->read( $integration );
		$url         = $credentials->get( 'url' );

		if ( '' === $url ) {
			return false;
		}

		$body = (string) wp_json_encode(
			array(
				'event'       => $event,
				'site'        => home_url(),
				'occurred_at' => $this->clock->now()->format( 'c' ),
				'data'        => $payload,
			)
		);

		$timestamp = $this->clock->now()->getTimestamp();

		$response = $this->http->postRaw(
			$url,
			$body,
			$this->signer->headers( $body, $credentials->get( 'secret' ), $timestamp, $event ),
			false
		);

		if ( $response->ok() ) {
			$this->record( $integrationId, $event, SyncStatus::Success, $attempt, $response->status, null );

			return true;
		}

		$willRetry = $this->retries->shouldRetry( $attempt, $response->isRetryable() );

		$this->record(
			$integrationId,
			$event,
			$willRetry ? SyncStatus::Retrying : SyncStatus::Failed,
			$attempt,
			$response->status,
			$response->errorMessage()
		);

		if ( $willRetry ) {
			$this->queue->scheduleAt(
				$this->retries->nextAttemptAt( $attempt, $timestamp ),
				WebhookDeliveryJob::hook(),
				array(
					'integration_id' => $integrationId,
					'event'          => $event,
					'payload'        => $payload,
					'attempt'        => $attempt + 1,
				)
			);
		}

		return false;
	}

	/**
	 * Which events an integration is subscribed to.
	 *
	 * @param array<string, mixed> $syncConfig Stored configuration.
	 * @return array<int, string>
	 */
	public function subscriptions( array $syncConfig ): array {
		$events = $syncConfig['events'] ?? null;

		if ( ! is_array( $events ) ) {
			return self::DEFAULT_EVENTS;
		}

		$clean = array();

		foreach ( $events as $event ) {
			if ( is_string( $event ) && in_array( $event, self::EVENTS, true ) ) {
				$clean[] = $event;
			}
		}

		return $clean;
	}

	/**
	 * Record one delivery attempt.
	 *
	 * The payload is not written. A `lead.captured` body holds an email
	 * address and a name, and a log row is the one place that data could
	 * survive an erasure request against the lead it describes.
	 *
	 * @param int         $integrationId Connection id.
	 * @param string      $event         Event name.
	 * @param SyncStatus  $status        Outcome.
	 * @param int         $attempt       Attempt number.
	 * @param int         $code          HTTP status.
	 * @param string|null $error         Failure reason.
	 * @return void
	 */
	private function record(
		int $integrationId,
		string $event,
		SyncStatus $status,
		int $attempt,
		int $code,
		?string $error
	): void {
		$this->log->append(
			new SyncLogEntry(
				id: null,
				integrationId: $integrationId,
				operation: SyncLogEntry::OP_WEBHOOK,
				status: $status,
				attempt: $attempt,
				requestSummary: array( 'event' => $event ),
				responseCode: 0 === $code ? null : $code,
				error: $error,
				createdAt: $this->clock->now(),
			)
		);
	}
}
