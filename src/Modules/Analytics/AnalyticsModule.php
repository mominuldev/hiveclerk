<?php
/**
 * Analytics module.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Analytics;

use Hiveclerk\Api\RestServer;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Module\AbstractModule;
use Hiveclerk\Core\Queue\JobRegistry;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Analytics\AnalyticsRepositoryInterface;
use Hiveclerk\Domain\Analytics\GapRepositoryInterface;
use Hiveclerk\Domain\Analytics\ReportSourceInterface;
use Hiveclerk\Domain\Analytics\RollupSourceInterface;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Integration\IntegrationRepositoryInterface;
use Hiveclerk\Domain\Integration\SyncLogRepositoryInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
use Hiveclerk\Domain\Knowledge\RetrievalResult;
use Hiveclerk\Modules\Analytics\Http\AnalyticsController;
use Hiveclerk\Modules\Analytics\Http\GapsController;
use Hiveclerk\Modules\Analytics\Jobs\RollupJob;
use Hiveclerk\Modules\Analytics\Services\AlertService;
use Hiveclerk\Modules\Analytics\Services\AnalyticsService;
use Hiveclerk\Modules\Analytics\Services\GapService;
use Hiveclerk\Modules\Analytics\Services\ReportExporter;
use Hiveclerk\Modules\Analytics\Services\RollupService;

/**
 * Proves the product is working, and says what to fix when it is not
 * (FR-ANL-01…07).
 *
 * Reads from every other module and is called by none of them. That is
 * the shape a reporting module should have, and it is what lets a site
 * filter this one out and keep a working clerk — the only thing that
 * stops is the counting.
 *
 * Registered after KnowledgeBase because the gap composer writes an FAQ
 * pair and queues its index run, and after Integrations because the
 * needs-attention queue reads the sync log.
 */
final class AnalyticsModule extends AbstractModule {

	/**
	 * Machine identifier.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'analytics';
	}

	/**
	 * Bind services.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {
		parent::register( $container );

		$container->singleton(
			RollupService::class,
			static fn ( Container $c ): RollupService => new RollupService(
				$c->get( RollupSourceInterface::class ),
				$c->get( AnalyticsRepositoryInterface::class ),
				$c->get( SettingsRepository::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			AnalyticsService::class,
			static fn ( Container $c ): AnalyticsService => new AnalyticsService(
				$c->get( AnalyticsRepositoryInterface::class ),
				$c->get( ReportSourceInterface::class ),
				$c->get( AgentRepositoryInterface::class ),
				$c->get( RollupService::class ),
				$c->get( SettingsRepository::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			AlertService::class,
			static fn ( Container $c ): AlertService => new AlertService(
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( AgentRepositoryInterface::class ),
				$c->get( GapRepositoryInterface::class ),
				$c->get( IntegrationRepositoryInterface::class ),
				$c->get( SyncLogRepositoryInterface::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			GapService::class,
			static fn ( Container $c ): GapService => new GapService(
				$c->get( GapRepositoryInterface::class ),
				$c->get( KnowledgeSourceRepositoryInterface::class ),
				$c->get( AgentRepositoryInterface::class ),
				$c->get( QueueInterface::class ),
				$c->get( AuditLogger::class )
			)
		);

		$container->singleton(
			ReportExporter::class,
			static fn ( Container $c ): ReportExporter => new ReportExporter(
				$c->get( AnalyticsService::class )
			)
		);

		$container->singleton(
			RollupJob::class,
			static fn ( Container $c ): RollupJob => new RollupJob(
				$c->get( RollupService::class ),
				$c->get( QueueInterface::class )
			)
		);

		$container->singleton(
			AnalyticsController::class,
			static fn ( Container $c ): AnalyticsController => new AnalyticsController(
				$c->get( AnalyticsService::class ),
				$c->get( AlertService::class ),
				$c->get( ReportExporter::class ),
				$c->get( AgentRepositoryInterface::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			GapsController::class,
			static fn ( Container $c ): GapsController => new GapsController(
				$c->get( GapRepositoryInterface::class ),
				$c->get( GapService::class ),
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
				$server->add( $this->container->get( AnalyticsController::class ) );
				$server->add( $this->container->get( GapsController::class ) );
			}
		);

		add_action(
			'hiveclerk/jobs/register',
			function ( JobRegistry $jobs ): void {
				$jobs->add( $this->container->get( RollupJob::class ) );
			}
		);

		/*
		 * Scheduled at boot rather than on activation. Both queue drivers
		 * make scheduleRecurring() idempotent, and scheduling on
		 * activation instead means a site that upgraded into this version
		 * never gets a tick — a dashboard that shows yesterday's figures
		 * forever, with nothing on screen to say why.
		 */
		$this->container->get( QueueInterface::class )->scheduleRecurring(
			RollupJob::INTERVAL,
			RollupJob::hook()
		);

		/*
		 * Gap detection listens rather than being called. ChatService must
		 * not know that knowledge gaps exist: it is the layer that spends
		 * money and its ordering is the product's cost model, not a place
		 * to add a feature. A listener that threw would take a visitor's
		 * reply with it, so the service it calls swallows its own errors.
		 */
		add_action(
			'hiveclerk/chat/retrieved',
			function ( Agent $agent, Conversation $conversation, string $message, ?RetrievalResult $result ): void {
				$this->container->get( GapService::class )->note( $agent, $conversation, $message, $result );
			},
			10,
			4
		);
	}

	/**
	 * Capabilities this module requires.
	 *
	 * @return array<int, string>
	 */
	public function capabilities(): array {
		return array( Capabilities::VIEW_CONVERSATIONS, Capabilities::MANAGE_KNOWLEDGE );
	}
}
