<?php
/**
 * Integration endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Http;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Licence\Feature;
use Hiveclerk\Core\Licence\LicenceGate;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Integration\CrmConnectorInterface;
use Hiveclerk\Domain\Integration\FieldMap;
use Hiveclerk\Domain\Integration\Integration;
use Hiveclerk\Domain\Integration\IntegrationRepositoryInterface;
use Hiveclerk\Domain\Integration\SyncLogEntry;
use Hiveclerk\Domain\Integration\SyncLogRepositoryInterface;
use Hiveclerk\Domain\Integration\SyncStatus;
use Hiveclerk\Domain\Lead\LeadCapture;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\QualificationQuestion;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Integrations\Services\ConnectorRegistry;
use Hiveclerk\Modules\Integrations\Services\FieldMapper;
use Hiveclerk\Modules\Integrations\Services\IntegrationService;
use Hiveclerk\Modules\Integrations\Services\OAuthService;
use Hiveclerk\Modules\Integrations\Services\SyncService;
use Hiveclerk\Modules\Integrations\Services\WebhookDispatcher;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The Integrations surface (D9 §3.6).
 *
 * Every route requires `manage_integrations`, including the OAuth
 * callback. That is not decoration: the callback is the request that
 * binds a CRM account to this site, and leaving it open — as "it only
 * carries a code the provider issued" would suggest — is how somebody
 * else's HubSpot portal ends up receiving the customer's leads. The
 * capability check and the state check are two independent locks and the
 * flow passes through both.
 */
final class IntegrationController extends AbstractController {

	/**
	 * Construct.
	 *
	 * @param IntegrationService             $service      Connection management.
	 * @param IntegrationRepositoryInterface $integrations Connection storage.
	 * @param SyncLogRepositoryInterface     $log          Attempt storage.
	 * @param ConnectorRegistry              $connectors   Connector lookup.
	 * @param FieldMapper                    $mapper       Source list.
	 * @param OAuthService                   $oauth        Redirect flow.
	 * @param AgentRepositoryInterface       $agents       Clerks, for their question keys.
	 * @param LicenceGate                    $licence      Tier entitlements.
	 * @param LeadRepositoryInterface        $leads        Leads.
	 * @param SyncService                    $sync         Outbound sync.
	 * @param AuditLogger                    $audit        Audit trail.
	 */
	public function __construct(
		private readonly IntegrationService $service,
		private readonly IntegrationRepositoryInterface $integrations,
		private readonly SyncLogRepositoryInterface $log,
		private readonly ConnectorRegistry $connectors,
		private readonly FieldMapper $mapper,
		private readonly OAuthService $oauth,
		private readonly AgentRepositoryInterface $agents,
		private readonly LicenceGate $licence,
		private readonly LeadRepositoryInterface $leads,
		private readonly SyncService $sync,
		private readonly AuditLogger $audit
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		$manage = $this->requires( Capabilities::MANAGE_INTEGRATIONS );

		register_rest_route(
			self::NAMESPACE,
			'/admin/integrations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => $manage,
			)
		);

		// Before the {provider} patterns: WordPress matches in registration
		// order and "log" is a legal provider slug.
		register_rest_route(
			self::NAMESPACE,
			'/admin/integrations/log',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'syncLog' ),
				'permission_callback' => $manage,
				'args'                => array_merge(
					$this->collectionArgs(),
					array(
						'provider' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
						'status'   => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/integrations/(?P<provider>[a-z0-9_-]+)/connect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'connect' ),
				'permission_callback' => $manage,
				'args'                => array(
					'settings' => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/integrations/(?P<provider>[a-z0-9_-]+)/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'callback' ),
				'permission_callback' => $manage,
				'args'                => array(
					'code'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'state' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/integrations/(?P<provider>[a-z0-9_-]+)/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test' ),
				'permission_callback' => $manage,
			)
		);

		/*
		 * A lead-shaped path registered by the integrations module, which
		 * looks misplaced and is not. Leads must not know that connectors
		 * exist: integrations listen to lead events and are never called
		 * by the module that fires them, which is what lets a site filter
		 * this whole module out and keep a working pipeline. Putting this
		 * handler on `LeadController` would have made `Leads` depend on
		 * `SyncService` and turned that one-way arrow into a cycle for
		 * the sake of a tidier filename.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/admin/leads/(?P<uuid>[0-9a-f-]{36})/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'syncLead' ),
				'permission_callback' => $manage,
				'args'                => array(
					'provider' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/integrations/(?P<provider>[a-z0-9_-]+)/fields',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'fields' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/integrations/(?P<provider>[a-z0-9_-]+)/mapping',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'mapping' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'saveMapping' ),
					'permission_callback' => $manage,
					'args'                => array(
						'mapping'         => array(
							'type'     => 'object',
							'required' => false,
						),
						'trigger'         => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
						'threshold'       => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'send_transcript' => array(
							'type'     => 'boolean',
							'required' => false,
						),
						'events'          => array(
							'type'     => 'array',
							'required' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/integrations/(?P<provider>[a-z0-9_-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'disconnect' ),
				'permission_callback' => $manage,
			)
		);
	}

	/**
	 * Every connector this site has, connected or not.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$cards = array();

		foreach ( $this->connectors->all() as $connector ) {
			$integration = $this->integrations->findByProvider( $connector->id() );

			$cards[] = $this->present( $connector, $integration );
		}

		return ApiResponse::ok(
			array(
				'integrations' => $cards,
				'events'       => WebhookDispatcher::EVENTS,
			)
		);
	}

	/**
	 * Store credentials, or start an OAuth redirect.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function connect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$connector = $this->connector( $request );

		if ( $connector instanceof WP_Error ) {
			return $connector;
		}

		/*
		 * FR-CRM-10, and only for the connectors that claim it.
		 *
		 * The descriptor's own `isPro` flag decides, rather than the
		 * endpoint deciding for every connector alike. Slack and the
		 * signed webhook are declared free on purpose: FR-CRM-09 calls
		 * the webhook the universal fallback, and its whole point is
		 * that a customer we will never write an adapter for can reach
		 * their CRM through Zapier or twenty lines of PHP. Charging for
		 * the fallback takes that away from exactly the users the free
		 * tier exists to win.
		 *
		 * Gated on the connect rather than on the read: the grid still
		 * lists every connector on a free install, because seeing what
		 * is available is how somebody decides to buy it. Nothing
		 * already connected is disconnected when a licence lapses — the
		 * sync stops, the configuration stays.
		 */
		if ( $connector->descriptor()->isPro ) {
			$refusal = $this->licence->refusal( Feature::Crm );

			if ( null !== $refusal ) {
				return $refusal;
			}
		}

		if ( ! $connector->isAvailable() ) {
			return ApiResponse::error(
				ErrorCode::PROVIDER_UNCONFIGURED,
				__( 'That integration needs its own plugin installed and active on this site first.', 'hiveclerk' ),
				409
			);
		}

		$result = $this->service->connect(
			$connector->id(),
			$this->cleanSettings( $connector, $request->get_param( 'settings' ) )
		);

		if ( null !== $result['error'] ) {
			return ApiResponse::error( ErrorCode::VALIDATION_FAILED, $result['error'], 422 );
		}

		return ApiResponse::ok(
			array(
				'integration' => $this->present( $connector, $result['integration'] ),
				'redirect'    => $result['redirect'],
			)
		);
	}

	/**
	 * Come back from the provider and finish the connection.
	 *
	 * Answers with a redirect rather than JSON: the browser arrived here
	 * by following the provider's own redirect, and an administrator
	 * looking at a raw API envelope has no way back to the screen they
	 * started on.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function callback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$connector = $this->connector( $request );

		if ( $connector instanceof WP_Error ) {
			return $connector;
		}

		$code  = (string) $request->get_param( 'code' );
		$state = (string) $request->get_param( 'state' );

		$error = '' === $code
			? __( 'The provider did not send an authorisation code.', 'hiveclerk' )
			: $this->oauth->complete( $connector, $code, $state );

		$target = add_query_arg( array( 'page' => 'hiveclerk' ), admin_url( 'admin.php' ) )
			. '#/integrations?provider=' . rawurlencode( $connector->id() )
			. ( null === $error ? '&connected=1' : '&error=' . rawurlencode( $error ) );

		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $target );

		return $response;
	}

	/**
	 * Run a live check.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$connector = $this->connector( $request );

		if ( $connector instanceof WP_Error ) {
			return $connector;
		}

		$integration = $this->integrations->findByProvider( $connector->id() );

		if ( null === $integration ) {
			return ApiResponse::error(
				ErrorCode::NOT_FOUND,
				__( 'That integration is not connected yet.', 'hiveclerk' ),
				404
			);
		}

		$result = $this->service->test( $integration );

		return ApiResponse::ok(
			array(
				'ok'          => $result->ok,
				'message'     => $result->message,
				'account'     => $result->account,
				'records'     => $result->records,
				'integration' => $this->present( $connector, $integration ),
			)
		);
	}

	/**
	 * Clear the credentials.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function disconnect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$connector = $this->connector( $request );

		if ( $connector instanceof WP_Error ) {
			return $connector;
		}

		$integration = $this->integrations->findByProvider( $connector->id() );

		if ( null === $integration ) {
			return ApiResponse::error(
				ErrorCode::NOT_FOUND,
				__( 'That integration is not connected.', 'hiveclerk' ),
				404
			);
		}

		return ApiResponse::ok(
			array(
				'integration' => $this->present( $connector, $this->service->disconnect( $integration ) ),
			)
		);
	}

	/**
	 * Push one lead to its destinations now (FR-CRM-09).
	 *
	 * Carried from Sprint 7 and deferred three times. `SyncService::push()`
	 * has existed since Sprint 8 with no caller: every sync until now was
	 * triggered by an event, which leaves an operator looking at a lead
	 * that failed to sync with nothing to press.
	 *
	 * The work is queued, never done inline. A CRM's API on a bad day is
	 * slower than any request should be, and the retry ladder the job
	 * already implements is the reason a failed sync eventually succeeds.
	 * What comes back is therefore "accepted", not "delivered", and the
	 * message says so rather than implying an outcome it cannot promise.
	 *
	 * `MANAGE_INTEGRATIONS` rather than `MANAGE_LEADS`: this sends a
	 * person's contact details to a third party, which is a decision about
	 * where the customer's data goes rather than one about the pipeline.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function syncLead( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$uuid = (string) $request->get_param( 'uuid' );

		$lead = Uuid::isValid( $uuid )
			? $this->leads->findByUuid( new Uuid( $uuid ) )
			: null;

		if ( null === $lead ) {
			return ApiResponse::error(
				ErrorCode::NOT_FOUND,
				__( 'No lead with that identifier.', 'hiveclerk' ),
				404
			);
		}

		$refusal = $this->licence->refusal( Feature::Crm );

		if ( $refusal instanceof WP_Error ) {
			return $refusal;
		}

		/*
		 * A lead with no address cannot be sent anywhere: every connector
		 * here identifies a contact by email. Refused at the boundary with
		 * an explanation rather than queued into a job that would drop it
		 * silently.
		 */
		if ( null === $lead->email ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				__( 'This lead has no email address, and every connector identifies a contact by one. Add an address first.', 'hiveclerk' ),
				422
			);
		}

		$requested = $request->get_param( 'provider' );
		$requested = is_string( $requested ) && '' !== $requested ? $requested : null;

		$queued  = array();
		$skipped = array();

		foreach ( $this->integrations->all() as $integration ) {
			if ( null !== $requested && $integration->provider !== $requested ) {
				continue;
			}

			/*
			 * `isUsable()` rather than "is connected". A connection whose
			 * token has expired is still in the list and can receive
			 * nothing, and queueing to it produces a job that fails its
			 * way through the whole retry ladder before telling anybody.
			 */
			if ( ! $integration->isUsable() || ! $this->sync->push( $integration, $lead ) ) {
				// Either unusable, or already pending — pressing the
				// button twice must not create two records in the
				// customer's CRM.
				$skipped[] = $integration->provider;

				continue;
			}

			$queued[] = $integration->provider;
		}

		$this->audit->record(
			'integration.lead_synced',
			array(
				'queued'  => $queued,
				'skipped' => $skipped,
			),
			'lead',
			$lead->id
		);

		return ApiResponse::ok(
			array(
				'queued'  => $queued,
				'skipped' => $skipped,
				'message' => array() === $queued
					? __( 'Nothing to send. Either no connector is usable, or this lead is already queued.', 'hiveclerk' )
					: __( 'Queued. The sync runs in the background and the log will show the outcome.', 'hiveclerk' ),
			)
		);
	}

	/**
	 * The fields available on both sides of the mapping screen.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function fields( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$connector = $this->connector( $request );

		if ( $connector instanceof WP_Error ) {
			return $connector;
		}

		$integration = $this->service->forProvider( $connector->id() );

		return ApiResponse::ok(
			array(
				'sources' => $this->mapper->sources( $this->answerKeys() ),
				'targets' => $this->service->targetFields( $integration ),
			)
		);
	}

	/**
	 * The current mapping and sync rules.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mapping( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$connector = $this->connector( $request );

		if ( $connector instanceof WP_Error ) {
			return $connector;
		}

		$integration = $this->service->forProvider( $connector->id() );

		return ApiResponse::ok( $this->presentMapping( $integration ) );
	}

	/**
	 * Replace the mapping and sync rules.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function saveMapping( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$connector = $this->connector( $request );

		if ( $connector instanceof WP_Error ) {
			return $connector;
		}

		$integration = $this->service->forProvider( $connector->id() );

		if ( null === $integration->id ) {
			return ApiResponse::error(
				ErrorCode::NOT_FOUND,
				__( 'Connect this integration before mapping its fields.', 'hiveclerk' ),
				404
			);
		}

		$config = $integration->syncConfig;

		if ( null !== $request->get_param( 'trigger' ) ) {
			$config['trigger'] = (string) $request->get_param( 'trigger' );
		}

		if ( null !== $request->get_param( 'threshold' ) ) {
			$config['threshold'] = (int) $request->get_param( 'threshold' );
		}

		if ( null !== $request->get_param( 'send_transcript' ) ) {
			$config['send_transcript'] = (bool) $request->get_param( 'send_transcript' );
		}

		$events = $request->get_param( 'events' );

		if ( is_array( $events ) ) {
			$clean = array();

			foreach ( $events as $event ) {
				$name = sanitize_text_field( (string) $event );

				if ( in_array( $name, WebhookDispatcher::EVENTS, true ) ) {
					$clean[] = $name;
				}
			}

			$config['events'] = array_values( array_unique( $clean ) );
		}

		$mapping = $request->get_param( 'mapping' );

		$integration = $this->service->saveMapping(
			$integration,
			FieldMap::fromArray( $this->cleanMapping( is_array( $mapping ) ? $mapping : array() ) ),
			$config
		);

		return ApiResponse::ok( $this->presentMapping( $integration ) );
	}

	/**
	 * A page of the sync log.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function syncLog( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->pagination( $request );

		$provider = $this->stringParam( $request, 'provider' );
		$status   = SyncStatus::tryFrom( (string) $this->stringParam( $request, 'status' ) );

		$integrationId = null;

		if ( null !== $provider ) {
			$integration = $this->integrations->findByProvider( $provider );
			// A filter naming a provider that was never connected must
			// return nothing rather than everything — -1 matches no row.
			$integrationId = null === $integration ? -1 : (int) $integration->id;
		}

		$names = array();

		foreach ( $this->integrations->all() as $integration ) {
			if ( null !== $integration->id ) {
				$names[ $integration->id ] = $integration->provider;
			}
		}

		$entries = array_map(
			static function ( SyncLogEntry $entry ) use ( $names ): array {
				return array(
					'id'            => $entry->id,
					'provider'      => $names[ $entry->integrationId ] ?? null,
					'operation'     => $entry->operation,
					'status'        => $entry->status->value,
					'status_label'  => $entry->status->label(),
					'lead_id'       => $entry->leadId,
					'attempt'       => $entry->attempt,
					'external_id'   => $entry->externalId,
					'summary'       => $entry->requestSummary,
					'response_code' => $entry->responseCode,
					'error'         => $entry->error,
					'next_retry_at' => null === $entry->nextRetryAt ? null : $entry->nextRetryAt->format( 'c' ),
					'created_at'    => null === $entry->createdAt ? null : $entry->createdAt->format( 'c' ),
				);
			},
			$this->log->paginate( $pagination, $integrationId, $status )
		);

		return ApiResponse::collection(
			$entries,
			$pagination,
			$this->log->countMatching( $integrationId, $status )
		);
	}

	/**
	 * One card on the grid.
	 *
	 * @param CrmConnectorInterface $connector   Connector.
	 * @param Integration|null      $integration Connection, when there is one.
	 * @return array<string, mixed>
	 */
	private function present( CrmConnectorInterface $connector, ?Integration $integration ): array {
		$descriptor = $connector->descriptor();

		$card = array_merge(
			$descriptor->toArray(),
			array(
				'available'    => $connector->isAvailable(),
				'status'       => 'disconnected',
				'status_label' => __( 'Not connected', 'hiveclerk' ),
				'account'      => null,
				'contacts'     => 0,
				'failures'     => 0,
				'last_sync_at' => null,
				'last_error'   => null,
				'expires_at'   => null,
				'trigger'      => 'qualified',
			)
		);

		if ( null === $integration || null === $integration->id ) {
			return $card;
		}

		return array_merge(
			$card,
			array(
				'status'       => $integration->status->value,
				'status_label' => $integration->status->label(),
				'account'      => $integration->name,
				'contacts'     => $this->log->contactsSynced( $integration->id ),
				'failures'     => $this->log->recentFailures( $integration->id ),
				'last_sync_at' => null === $integration->lastSyncAt ? null : $integration->lastSyncAt->format( 'c' ),
				'last_error'   => $integration->lastError,
				'expires_at'   => null === $integration->tokenExpiresAt ? null : $integration->tokenExpiresAt->format( 'c' ),
				'trigger'      => $integration->trigger()->value,
			)
		);
	}

	/**
	 * The mapping screen's state.
	 *
	 * @param Integration $integration Connection.
	 * @return array<string, mixed>
	 */
	private function presentMapping( Integration $integration ): array {
		return array(
			'provider'        => $integration->provider,
			'mapping'         => $integration->fieldMap->toArray(),
			'trigger'         => $integration->trigger()->value,
			'threshold'       => $integration->threshold(),
			'send_transcript' => $integration->sendsTranscript(),
			'events'          => array_values(
				array_filter(
					WebhookDispatcher::EVENTS,
					fn ( string $event ): bool => in_array(
						$event,
						$this->subscribedEvents( $integration ),
						true
					)
				)
			),
			'connected'       => $integration->isUsable(),
		);
	}

	/**
	 * Which events an integration is subscribed to.
	 *
	 * @param Integration $integration Connection.
	 * @return array<int, string>
	 */
	private function subscribedEvents( Integration $integration ): array {
		$events = $integration->syncConfig['events'] ?? null;

		if ( ! is_array( $events ) ) {
			return WebhookDispatcher::DEFAULT_EVENTS;
		}

		$clean = array();

		foreach ( $events as $event ) {
			if ( is_string( $event ) ) {
				$clean[] = $event;
			}
		}

		return $clean;
	}

	/**
	 * Resolve the connector named in the route.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return CrmConnectorInterface|WP_Error
	 */
	private function connector( WP_REST_Request $request ): CrmConnectorInterface|WP_Error {
		$connector = $this->connectors->get( sanitize_key( (string) $request->get_param( 'provider' ) ) );

		if ( null === $connector ) {
			return ApiResponse::error(
				ErrorCode::NOT_FOUND,
				__( 'There is no integration by that name.', 'hiveclerk' ),
				404
			);
		}

		return $connector;
	}

	/**
	 * Sanitise a submitted credential bag, key by key.
	 *
	 * Only the fields the connector declared are accepted. A JSON column
	 * is read back by code that builds HTTP requests out of it, so an
	 * unexpected key here would be an unexpected key on the wire.
	 *
	 * @param CrmConnectorInterface $connector Connector.
	 * @param mixed                 $settings  Raw settings.
	 * @return array<string, string>
	 */
	private function cleanSettings( CrmConnectorInterface $connector, mixed $settings ): array {
		if ( ! is_array( $settings ) ) {
			return array();
		}

		$clean = array();

		foreach ( $connector->descriptor()->settings as $setting ) {
			$value = $settings[ $setting->key ] ?? null;

			if ( ! is_string( $value ) ) {
				continue;
			}

			// A URL keeps its query string, which is where a Slack webhook
			// and most signed endpoints carry their identity;
			// sanitize_text_field would leave it intact but esc_url_raw is
			// the function that knows what a URL is allowed to contain.
			$clean[ $setting->key ] = 'url' === $setting->type
				? esc_url_raw( trim( $value ) )
				: sanitize_text_field( $value );
		}

		return $clean;
	}

	/**
	 * Sanitise a submitted mapping, key by key.
	 *
	 * @param array<mixed> $mapping Raw mapping.
	 * @return array<string, string>
	 */
	private function cleanMapping( array $mapping ): array {
		$clean = array();

		foreach ( $mapping as $source => $target ) {
			if ( ! is_string( $source ) || ! is_string( $target ) ) {
				continue;
			}

			$clean[ sanitize_text_field( $source ) ] = sanitize_text_field( $target );
		}

		return $clean;
	}

	/**
	 * Every qualification question key configured on any clerk.
	 *
	 * The mapping screen offers these as sources, so a customer who asks
	 * "what is your budget" can put the answer in a CRM field. There is no
	 * other list of them — they are whatever was typed into a clerk.
	 *
	 * @return array<int, string>
	 */
	private function answerKeys(): array {
		$keys = array();

		foreach ( $this->agents->published() as $agent ) {
			$capture = LeadCapture::fromArray( $agent->leadConfig );

			foreach ( $capture->questions as $question ) {
				if ( $question instanceof QualificationQuestion ) {
					$keys[ $question->key ] = true;
				}
			}
		}

		return array_keys( $keys );
	}
}
