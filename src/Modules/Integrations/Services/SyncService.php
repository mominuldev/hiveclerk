<?php
/**
 * Lead synchronisation.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Services;

use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Integration\Integration;
use Hiveclerk\Domain\Integration\IntegrationRepositoryInterface;
use Hiveclerk\Domain\Integration\IntegrationStatus;
use Hiveclerk\Domain\Integration\SyncLogEntry;
use Hiveclerk\Domain\Integration\SyncLogRepositoryInterface;
use Hiveclerk\Domain\Integration\SyncResult;
use Hiveclerk\Domain\Integration\SyncStatus;
use Hiveclerk\Domain\Integration\SyncTrigger;
use Hiveclerk\Domain\Lead\Activity;
use Hiveclerk\Domain\Lead\ActivityRepositoryInterface;
use Hiveclerk\Domain\Lead\ActivityType;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Modules\Integrations\Jobs\SyncLeadJob;
use DateTimeImmutable;

/**
 * Decides what gets pushed, pushes it, and records what happened.
 *
 * ## Nothing here runs in a web request
 *
 * `dispatch()` is called from an event listener and only enqueues. The
 * push itself happens in `SyncLeadJob`, because a CRM's API is between
 * one and ten seconds away on a good day and a visitor's reply must not
 * wait for HubSpot. That is the same rule the whole plugin runs on:
 * anything slow is a job, never a request.
 *
 * ## Why a push is only ever attempted once per trigger
 *
 * The trigger check happens at dispatch. A lead whose score crosses the
 * threshold four times in one conversation would otherwise be pushed four
 * times — four API calls, four log rows, and on a per-contact-priced CRM,
 * four chances to be told the plan is full. The queue is asked whether
 * the same job is already pending, and the connector's own upsert covers
 * the rest.
 */
final class SyncService {

	/**
	 * Construct.
	 *
	 * @param IntegrationRepositoryInterface $integrations Connection storage.
	 * @param SyncLogRepositoryInterface     $log          Attempt storage.
	 * @param ConnectorRegistry              $connectors   Connector lookup.
	 * @param FieldMapper                    $mapper       Payload builder.
	 * @param RetryPolicy                    $retries      Backoff schedule.
	 * @param OAuthService                   $oauth        Token refresh.
	 * @param LeadRepositoryInterface        $leads        Lead lookup.
	 * @param ActivityRepositoryInterface    $activities   Lead timeline.
	 * @param QueueInterface                 $queue        Background work.
	 * @param ClockInterface                 $clock        Clock.
	 */
	public function __construct(
		private readonly IntegrationRepositoryInterface $integrations,
		private readonly SyncLogRepositoryInterface $log,
		private readonly ConnectorRegistry $connectors,
		// Credentials are read through OAuthService rather than the store
		// directly: every push needs a token that is valid *now*, and the
		// refresh decision is the one thing standing between a job and a
		// fifteen-hour retry loop against an expired token.
		private readonly FieldMapper $mapper,
		private readonly RetryPolicy $retries,
		private readonly OAuthService $oauth,
		private readonly LeadRepositoryInterface $leads,
		private readonly ActivityRepositoryInterface $activities,
		private readonly QueueInterface $queue,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Queue a push to every connection whose trigger this event satisfies.
	 *
	 * @param Lead        $lead    The lead.
	 * @param SyncTrigger $trigger What just happened.
	 * @return int How many pushes were queued.
	 */
	public function dispatch( Lead $lead, SyncTrigger $trigger ): int {
		if ( null === $lead->id ) {
			return 0;
		}

		$queued = 0;

		foreach ( $this->integrations->usable() as $integration ) {
			if ( ! $this->shouldSync( $integration, $lead, $trigger ) ) {
				continue;
			}

			if ( $this->enqueue( $integration, $lead ) ) {
				++$queued;
			}
		}

		return $queued;
	}

	/**
	 * Queue one push, by hand, from the lead screen.
	 *
	 * @param Integration $integration Connection.
	 * @param Lead        $lead        The lead.
	 * @return bool
	 */
	public function push( Integration $integration, Lead $lead ): bool {
		return $this->enqueue( $integration, $lead );
	}

	/**
	 * Whether this event means this lead belongs in this destination.
	 *
	 * @param Integration $integration Connection.
	 * @param Lead        $lead        The lead.
	 * @param SyncTrigger $trigger     What just happened.
	 * @return bool
	 */
	public function shouldSync( Integration $integration, Lead $lead, SyncTrigger $trigger ): bool {
		if ( ! $integration->isUsable() || null === $lead->id ) {
			return false;
		}

		// Every destination here identifies a contact by address. A lead
		// with only a phone number is a real lead and belongs on the
		// board; it is not something any of these connectors can store.
		if ( null === $lead->email ) {
			return false;
		}

		$configured = $integration->trigger();

		if ( SyncTrigger::Manual === $configured ) {
			return SyncTrigger::Manual === $trigger;
		}

		if ( SyncTrigger::ScoreAbove === $configured ) {
			return $lead->score >= $integration->threshold();
		}

		return $configured === $trigger;
	}

	/**
	 * Run one attempt and decide what happens next.
	 *
	 * Called from the job, never from a request.
	 *
	 * @param Integration $integration Connection.
	 * @param Lead        $lead        The lead.
	 * @param int         $attempt     1-based attempt number.
	 * @return SyncResult
	 */
	public function attempt( Integration $integration, Lead $lead, int $attempt ): SyncResult {
		$connector = $this->connectors->get( $integration->provider );

		if ( null === $connector || null === $integration->id || null === $lead->id ) {
			return SyncResult::permanent( __( 'That integration is no longer installed.', 'hiveclerk' ) );
		}

		$credentials = $this->oauth->fresh( $integration, $connector );

		if ( null === $credentials ) {
			$this->record(
				$integration,
				$lead,
				SyncStatus::Failed,
				$attempt,
				SyncResult::permanent( __( 'The stored credentials could not be refreshed. Reconnect this integration.', 'hiveclerk' ) ),
				null
			);

			$integration->status = IntegrationStatus::Expired;
			$this->integrations->save( $integration );

			return SyncResult::permanent( __( 'The stored credentials could not be refreshed.', 'hiveclerk' ) );
		}

		$result = $connector->pushContact(
			$lead,
			$integration->fieldMap,
			$credentials,
			$this->mapper->build( $lead, $integration )
		);

		if ( $result->ok ) {
			$this->record( $integration, $lead, SyncStatus::Success, $attempt, $result, null );

			$integration->recordSuccess( $this->clock->now() );
			$this->integrations->save( $integration );

			$this->noteOnTimeline( $integration, $lead, $result );

			/**
			 * Fires after a lead reached a destination.
			 *
			 * @param Lead        $lead        The lead.
			 * @param Integration $integration The connection.
			 * @param SyncResult  $result      What came back.
			 */
			do_action( 'hiveclerk/crm/synced', $lead, $integration, $result );

			return $result;
		}

		$willRetry = $this->retries->shouldRetry( $attempt, $result->retryable );
		$nextAt    = $willRetry
			? $this->at( $this->retries->nextAttemptAt( $attempt, $this->clock->now()->getTimestamp() ) )
			: null;

		$this->record(
			$integration,
			$lead,
			$willRetry ? SyncStatus::Retrying : SyncStatus::Failed,
			$attempt,
			$result,
			$nextAt
		);

		if ( $willRetry && null !== $nextAt ) {
			$this->queue->scheduleAt(
				$nextAt->getTimestamp(),
				SyncLeadJob::hook(),
				array(
					'integration_id' => $integration->id,
					'lead_id'        => $lead->id,
					'attempt'        => $attempt + 1,
				)
			);

			return $result;
		}

		$integration->recordFailure( (string) $result->error );
		$this->integrations->save( $integration );

		/**
		 * Fires when a lead will not reach a destination.
		 *
		 * @param Lead        $lead        The lead.
		 * @param Integration $integration The connection.
		 * @param SyncResult  $result      What came back.
		 */
		do_action( 'hiveclerk/crm/sync_failed', $lead, $integration, $result );

		return $result;
	}

	/**
	 * The lead behind a job argument.
	 *
	 * @param int $leadId Lead id.
	 * @return Lead|null
	 */
	public function lead( int $leadId ): ?Lead {
		return $this->leads->find( $leadId );
	}

	/**
	 * The connection behind a job argument.
	 *
	 * @param int $integrationId Connection id.
	 * @return Integration|null
	 */
	public function integration( int $integrationId ): ?Integration {
		return $this->integrations->find( $integrationId );
	}

	/**
	 * Queue one push if the same one is not already waiting.
	 *
	 * @param Integration $integration Connection.
	 * @param Lead        $lead        The lead.
	 * @return bool
	 */
	private function enqueue( Integration $integration, Lead $lead ): bool {
		if ( null === $integration->id || null === $lead->id ) {
			return false;
		}

		$args = array(
			'integration_id' => $integration->id,
			'lead_id'        => $lead->id,
			'attempt'        => 1,
		);

		if ( $this->queue->isPending( SyncLeadJob::hook(), $args ) ) {
			return false;
		}

		return $this->queue->enqueue( SyncLeadJob::hook(), $args );
	}

	/**
	 * Write one attempt to the log.
	 *
	 * @param Integration            $integration Connection.
	 * @param Lead                   $lead        The lead.
	 * @param SyncStatus             $status      Outcome.
	 * @param int                    $attempt     Attempt number.
	 * @param SyncResult             $result      What came back.
	 * @param DateTimeImmutable|null $nextRetryAt When the next attempt is due.
	 * @return void
	 */
	private function record(
		Integration $integration,
		Lead $lead,
		SyncStatus $status,
		int $attempt,
		SyncResult $result,
		?DateTimeImmutable $nextRetryAt
	): void {
		if ( null === $integration->id ) {
			return;
		}

		$this->log->append(
			new SyncLogEntry(
				id: null,
				integrationId: $integration->id,
				operation: SyncLogEntry::OP_PUSH_CONTACT,
				status: $status,
				leadId: $lead->id,
				attempt: $attempt,
				externalId: $result->externalId,
				requestSummary: $result->summary,
				responseCode: $result->statusCode,
				error: $result->error,
				nextRetryAt: $nextRetryAt,
				createdAt: $this->clock->now(),
			)
		);
	}

	/**
	 * Put the sync on the lead's own timeline.
	 *
	 * On the timeline rather than in the audit log, for the reason
	 * Sprint 7 settled: the audit log answers "who changed the
	 * configuration of this site", and a hundred synced leads a day would
	 * bury the one entry in it that matters.
	 *
	 * @param Integration $integration Connection.
	 * @param Lead        $lead        The lead.
	 * @param SyncResult  $result      What came back.
	 * @return void
	 */
	private function noteOnTimeline( Integration $integration, Lead $lead, SyncResult $result ): void {
		if ( null === $lead->id ) {
			return;
		}

		$connector = $this->connectors->get( $integration->provider );
		$name      = null === $connector ? $integration->provider : $connector->descriptor()->name;

		$this->activities->record(
			new Activity(
				id: null,
				type: ActivityType::CrmSynced,
				title: sprintf(
					/* translators: %s: connector name. */
					__( 'Synced to %s', 'hiveclerk' ),
					$name
				),
				leadId: $lead->id,
				metadata: array(
					'provider'    => $integration->provider,
					'external_id' => $result->externalId,
				),
				createdAt: $this->clock->now(),
			)
		);
	}

	/**
	 * A Unix timestamp as a UTC point in time.
	 *
	 * @param int $timestamp Unix time.
	 * @return DateTimeImmutable
	 */
	private function at( int $timestamp ): DateTimeImmutable {
		return ( new DateTimeImmutable( '@' . $timestamp ) )
			->setTimezone( new \DateTimeZone( 'UTC' ) );
	}
}
