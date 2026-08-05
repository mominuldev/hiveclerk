<?php
/**
 * Integrations module.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations;

use Hiveclerk\Api\RestServer;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Module\AbstractModule;
use Hiveclerk\Core\Queue\JobRegistry;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Core\Support\Encryptor;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Integration\IntegrationRepositoryInterface;
use Hiveclerk\Domain\Integration\SyncLogRepositoryInterface;
use Hiveclerk\Domain\Integration\SyncTrigger;
use Hiveclerk\Domain\Lead\ActivityRepositoryInterface;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;
use Hiveclerk\Infrastructure\Http\OutboundUrlGuard;
use Hiveclerk\Modules\Integrations\Connectors\FluentCrmConnector;
use Hiveclerk\Modules\Integrations\Connectors\GroundhoggConnector;
use Hiveclerk\Modules\Integrations\Connectors\HubSpotConnector;
use Hiveclerk\Modules\Integrations\Connectors\SlackConnector;
use Hiveclerk\Modules\Integrations\Connectors\WebhookConnector;
use Hiveclerk\Modules\Integrations\Http\IntegrationController;
use Hiveclerk\Modules\Integrations\Jobs\SyncLeadJob;
use Hiveclerk\Modules\Integrations\Jobs\WebhookDeliveryJob;
use Hiveclerk\Modules\Integrations\Services\ConnectorRegistry;
use Hiveclerk\Modules\Integrations\Services\CredentialStore;
use Hiveclerk\Modules\Integrations\Services\FieldMapper;
use Hiveclerk\Modules\Integrations\Services\IntegrationService;
use Hiveclerk\Modules\Integrations\Services\OAuthService;
use Hiveclerk\Modules\Integrations\Services\RetryPolicy;
use Hiveclerk\Modules\Integrations\Services\SyncService;
use Hiveclerk\Modules\Integrations\Services\WebhookDispatcher;
use Hiveclerk\Modules\Integrations\Support\ConnectorHttp;
use Hiveclerk\Modules\Integrations\Support\WebhookSigner;

/**
 * Leads leave the building (FR-CRM-01…10).
 *
 * ## Everything here listens; nothing here is called
 *
 * The Leads module does not know this exists. It fires
 * `hiveclerk/lead/captured` and `hiveclerk/lead/qualified` because those
 * are facts about a lead, and this module decides what they mean for a
 * CRM. That is what lets a site filter this module out entirely and keep
 * a working pipeline — and it is why a customer who has connected nothing
 * pays no cost for the feature existing.
 *
 * The listeners only ever enqueue. A visitor's reply must not wait for
 * HubSpot, and a CRM being down must not make the chat widget slow.
 */
final class IntegrationsModule extends AbstractModule {

	/**
	 * Machine identifier.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'integrations';
	}

	/**
	 * Bind services.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {
		parent::register( $container );

		$container->singleton( OutboundUrlGuard::class, static fn (): OutboundUrlGuard => new OutboundUrlGuard() );
		$container->singleton( WebhookSigner::class, static fn (): WebhookSigner => new WebhookSigner() );
		$container->singleton( RetryPolicy::class, static fn (): RetryPolicy => new RetryPolicy() );

		$container->singleton(
			ConnectorHttp::class,
			static fn ( Container $c ): ConnectorHttp => new ConnectorHttp(
				$c->get( OutboundUrlGuard::class )
			)
		);

		$container->singleton(
			ConnectorRegistry::class,
			static fn ( Container $c ): ConnectorRegistry => new ConnectorRegistry(
				array(
					new FluentCrmConnector(),
					new GroundhoggConnector(),
					new HubSpotConnector(
						$c->get( ConnectorHttp::class ),
						$c->get( ClockInterface::class )
					),
					new WebhookConnector(
						$c->get( ConnectorHttp::class ),
						$c->get( WebhookSigner::class ),
						$c->get( ClockInterface::class )
					),
					new SlackConnector( $c->get( ConnectorHttp::class ) ),
				)
			)
		);

		$container->singleton(
			CredentialStore::class,
			static fn ( Container $c ): CredentialStore => new CredentialStore(
				$c->get( IntegrationRepositoryInterface::class ),
				$c->get( Encryptor::class )
			)
		);

		$container->singleton(
			OAuthService::class,
			static fn ( Container $c ): OAuthService => new OAuthService(
				$c->get( IntegrationRepositoryInterface::class ),
				$c->get( CredentialStore::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			FieldMapper::class,
			static fn ( Container $c ): FieldMapper => new FieldMapper(
				$c->get( LeadStageRepositoryInterface::class ),
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( MessageRepositoryInterface::class )
			)
		);

		$container->singleton(
			SyncService::class,
			static fn ( Container $c ): SyncService => new SyncService(
				$c->get( IntegrationRepositoryInterface::class ),
				$c->get( SyncLogRepositoryInterface::class ),
				$c->get( ConnectorRegistry::class ),
				$c->get( FieldMapper::class ),
				$c->get( RetryPolicy::class ),
				$c->get( OAuthService::class ),
				$c->get( LeadRepositoryInterface::class ),
				$c->get( ActivityRepositoryInterface::class ),
				$c->get( QueueInterface::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			WebhookDispatcher::class,
			static fn ( Container $c ): WebhookDispatcher => new WebhookDispatcher(
				$c->get( IntegrationRepositoryInterface::class ),
				$c->get( SyncLogRepositoryInterface::class ),
				$c->get( CredentialStore::class ),
				$c->get( ConnectorHttp::class ),
				$c->get( WebhookSigner::class ),
				$c->get( RetryPolicy::class ),
				$c->get( QueueInterface::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			IntegrationService::class,
			static fn ( Container $c ): IntegrationService => new IntegrationService(
				$c->get( IntegrationRepositoryInterface::class ),
				$c->get( SyncLogRepositoryInterface::class ),
				$c->get( ConnectorRegistry::class ),
				$c->get( CredentialStore::class ),
				$c->get( OAuthService::class ),
				$c->get( AuditLogger::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			SyncLeadJob::class,
			static fn ( Container $c ): SyncLeadJob => new SyncLeadJob( $c->get( SyncService::class ) )
		);

		$container->singleton(
			WebhookDeliveryJob::class,
			static fn ( Container $c ): WebhookDeliveryJob => new WebhookDeliveryJob(
				$c->get( WebhookDispatcher::class )
			)
		);

		$container->singleton(
			IntegrationController::class,
			static fn ( Container $c ): IntegrationController => new IntegrationController(
				$c->get( IntegrationService::class ),
				$c->get( IntegrationRepositoryInterface::class ),
				$c->get( SyncLogRepositoryInterface::class ),
				$c->get( ConnectorRegistry::class ),
				$c->get( FieldMapper::class ),
				$c->get( OAuthService::class ),
				$c->get( AgentRepositoryInterface::class )
			)
		);
	}

	/**
	 * Attach hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action(
			'hiveclerk/rest/register',
			function ( RestServer $server ): void {
				$server->add( $this->container->get( IntegrationController::class ) );
			}
		);

		add_action(
			'hiveclerk/jobs/register',
			function ( JobRegistry $jobs ): void {
				$jobs->add( $this->container->get( SyncLeadJob::class ) );
				$jobs->add( $this->container->get( WebhookDeliveryJob::class ) );
			}
		);

		$this->listenForLeads();
		$this->listenForWebhooks();
	}

	/**
	 * Queue CRM pushes off the lead lifecycle.
	 *
	 * @return void
	 */
	private function listenForLeads(): void {
		add_action(
			'hiveclerk/lead/captured',
			function ( Lead $lead ): void {
				$this->container->get( SyncService::class )->dispatch( $lead, SyncTrigger::Captured );
			}
		);

		add_action(
			'hiveclerk/lead/qualified',
			function ( Lead $lead ): void {
				$this->container->get( SyncService::class )->dispatch( $lead, SyncTrigger::Qualified );
			}
		);

		add_action(
			'hiveclerk/lead/stage_changed',
			function ( Lead $lead ): void {
				$this->container->get( SyncService::class )->dispatch( $lead, SyncTrigger::StageMoved );
			}
		);
	}

	/**
	 * Forward the documented events to a customer's endpoint.
	 *
	 * The payloads are deliberately thin — identifiers and the fact that
	 * happened, not the lead's contact details. A receiver that wants the
	 * person reads them back over the API with its own credentials, which
	 * keeps a webhook body from being a copy of the customer's database
	 * sitting in somebody's request log.
	 *
	 * @return void
	 */
	private function listenForWebhooks(): void {
		add_action(
			'hiveclerk/conversation/started',
			function ( Conversation $conversation ): void {
				$this->dispatcher()->dispatch(
					'conversation.started',
					array(
						'conversation_id' => $conversation->uuid->value,
						'agent_id'        => $conversation->agentId,
					)
				);
			}
		);

		add_action(
			'hiveclerk/conversation/handoff_requested',
			function ( Conversation $conversation ): void {
				$this->dispatcher()->dispatch(
					'conversation.handoff_requested',
					array( 'conversation_id' => $conversation->uuid->value )
				);
			}
		);

		add_action(
			'hiveclerk/lead/captured',
			function ( Lead $lead ): void {
				$this->dispatcher()->dispatch(
					'lead.captured',
					array(
						'lead_id' => $lead->uuid->value,
						'score'   => $lead->score,
					)
				);
			}
		);

		add_action(
			'hiveclerk/lead/qualified',
			function ( Lead $lead ): void {
				$this->dispatcher()->dispatch(
					'lead.qualified',
					array(
						'lead_id' => $lead->uuid->value,
						'score'   => $lead->score,
					)
				);
			}
		);

		add_action(
			'hiveclerk/lead/stage_changed',
			function ( Lead $lead, ?int $stageId ): void {
				$this->dispatcher()->dispatch(
					'lead.stage_changed',
					array(
						'lead_id'  => $lead->uuid->value,
						'stage_id' => $stageId,
					)
				);
			},
			10,
			2
		);
	}

	/**
	 * The webhook dispatcher, resolved late.
	 *
	 * @return WebhookDispatcher
	 */
	private function dispatcher(): WebhookDispatcher {
		return $this->container->get( WebhookDispatcher::class );
	}

	/**
	 * Capabilities this module requires.
	 *
	 * @return array<int, string>
	 */
	public function capabilities(): array {
		return array( Capabilities::MANAGE_INTEGRATIONS );
	}
}
