<?php
/**
 * Leads module.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads;

use Hiveclerk\Ai\AiServiceInterface;
use Hiveclerk\Api\RestServer;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Module\AbstractModule;
use Hiveclerk\Core\Queue\JobRegistry;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Lead\ActivityRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;
use Hiveclerk\Domain\Lead\ScoreEventRepositoryInterface;
use Hiveclerk\Domain\Lead\VisitorRepositoryInterface;
use Hiveclerk\Domain\Lead\VisitorResolverInterface;
use Hiveclerk\Infrastructure\Http\OutboundUrlGuard;
use Hiveclerk\Modules\Chat\Services\SessionService;
use Hiveclerk\Modules\Chat\Services\WidgetConfig;
use Hiveclerk\Modules\Leads\Http\CaptureController;
use Hiveclerk\Modules\Leads\Http\LeadController;
use Hiveclerk\Modules\Leads\Http\PipelineController;
use Hiveclerk\Modules\Leads\Jobs\ScoreLeadJob;
use Hiveclerk\Modules\Leads\Services\AiScorer;
use Hiveclerk\Modules\Leads\Services\LeadCaptureService;
use Hiveclerk\Modules\Leads\Services\LeadExporter;
use Hiveclerk\Modules\Leads\Services\LeadNotifier;
use Hiveclerk\Modules\Leads\Services\LeadService;
use Hiveclerk\Modules\Leads\Services\PipelineService;
use Hiveclerk\Modules\Leads\Services\ScoringPolicy;
use Hiveclerk\Modules\Leads\Services\ScoringService;
use Hiveclerk\Modules\Leads\Services\SignalCollector;
use Hiveclerk\Modules\Leads\Services\VisitorService;
use Hiveclerk\Modules\Leads\Support\AnswerMatcher;
use Hiveclerk\Modules\Leads\Support\ContactExtractor;

/**
 * The revenue mechanism (FR-LED-01…10).
 *
 * Depends on Chat for the public-route base class and for session
 * validation, and that direction is deliberate: a site can hold
 * conversations without ever capturing a lead, and a lead that did not
 * come from a conversation is the exception this product handles rather
 * than the case it exists for.
 *
 * The dependency the other way is one interface. Chat needs a visitor id
 * on a conversation from the moment it opens, and it gets that through
 * `VisitorResolverInterface` in the domain rather than by importing
 * anything from here — which is what lets a site filter this module out
 * and still run a widget.
 */
final class LeadsModule extends AbstractModule {

	/**
	 * Machine identifier.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'leads';
	}

	/**
	 * Bind services.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {
		parent::register( $container );

		$container->singleton( ContactExtractor::class, static fn (): ContactExtractor => new ContactExtractor() );
		$container->singleton( AnswerMatcher::class, static fn (): AnswerMatcher => new AnswerMatcher() );
		$container->singleton( OutboundUrlGuard::class, static fn (): OutboundUrlGuard => new OutboundUrlGuard() );

		$container->singleton(
			ScoringPolicy::class,
			static fn ( Container $c ): ScoringPolicy => new ScoringPolicy(
				$c->get( SettingsRepository::class )
			)
		);

		$container->singleton(
			VisitorService::class,
			static fn ( Container $c ): VisitorService => new VisitorService(
				$c->get( VisitorRepositoryInterface::class ),
				$c->get( ActivityRepositoryInterface::class ),
				$c->get( ClockInterface::class )
			)
		);

		// Replaces the null object bound by the core provider, which is
		// what gives a widget session its visitor id.
		$container->singleton(
			VisitorResolverInterface::class,
			static fn ( Container $c ): VisitorResolverInterface => $c->get( VisitorService::class )
		);

		$container->singleton(
			SignalCollector::class,
			static fn ( Container $c ): SignalCollector => new SignalCollector(
				$c->get( MessageRepositoryInterface::class ),
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( VisitorRepositoryInterface::class )
			)
		);

		$container->singleton(
			LeadNotifier::class,
			static fn ( Container $c ): LeadNotifier => new LeadNotifier(
				$c->get( ScoringPolicy::class ),
				$c->get( ActivityRepositoryInterface::class ),
				$c->get( OutboundUrlGuard::class )
			)
		);

		$container->singleton(
			ScoringService::class,
			static fn ( Container $c ): ScoringService => new ScoringService(
				$c->get( LeadRepositoryInterface::class ),
				$c->get( ScoreEventRepositoryInterface::class ),
				$c->get( ActivityRepositoryInterface::class ),
				$c->get( ScoringPolicy::class ),
				$c->get( SignalCollector::class ),
				$c->get( LeadNotifier::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			LeadService::class,
			static fn ( Container $c ): LeadService => new LeadService(
				$c->get( LeadRepositoryInterface::class ),
				$c->get( LeadStageRepositoryInterface::class ),
				$c->get( ActivityRepositoryInterface::class ),
				$c->get( ScoreEventRepositoryInterface::class ),
				$c->get( VisitorRepositoryInterface::class ),
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( ScoringService::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			LeadCaptureService::class,
			static fn ( Container $c ): LeadCaptureService => new LeadCaptureService(
				$c->get( LeadRepositoryInterface::class ),
				$c->get( LeadStageRepositoryInterface::class ),
				$c->get( MessageRepositoryInterface::class ),
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( VisitorRepositoryInterface::class ),
				$c->get( VisitorService::class ),
				$c->get( ScoringService::class ),
				$c->get( LeadService::class ),
				$c->get( ContactExtractor::class ),
				$c->get( AnswerMatcher::class ),
				$c->get( QueueInterface::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			AiScorer::class,
			static fn ( Container $c ): AiScorer => new AiScorer(
				$c->get( AiServiceInterface::class ),
				$c->get( MessageRepositoryInterface::class )
			)
		);

		$container->singleton(
			ScoreLeadJob::class,
			static fn ( Container $c ): ScoreLeadJob => new ScoreLeadJob(
				$c->get( AiScorer::class ),
				$c->get( ScoringService::class ),
				$c->get( LeadRepositoryInterface::class ),
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( AgentRepositoryInterface::class )
			)
		);

		$container->singleton(
			PipelineService::class,
			static fn ( Container $c ): PipelineService => new PipelineService(
				$c->get( LeadStageRepositoryInterface::class ),
				$c->get( LeadRepositoryInterface::class ),
				$c->get( AuditLogger::class )
			)
		);

		$container->singleton(
			LeadExporter::class,
			static fn ( Container $c ): LeadExporter => new LeadExporter(
				$c->get( LeadRepositoryInterface::class ),
				$c->get( LeadStageRepositoryInterface::class )
			)
		);

		$container->singleton(
			LeadController::class,
			static fn ( Container $c ): LeadController => new LeadController(
				$c->get( LeadService::class ),
				$c->get( LeadRepositoryInterface::class ),
				$c->get( LeadStageRepositoryInterface::class ),
				$c->get( ScoringService::class ),
				$c->get( LeadExporter::class ),
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( AgentRepositoryInterface::class )
			)
		);

		$container->singleton(
			PipelineController::class,
			static fn ( Container $c ): PipelineController => new PipelineController(
				$c->get( PipelineService::class ),
				$c->get( LeadStageRepositoryInterface::class ),
				$c->get( ScoringPolicy::class ),
				$c->get( AuditLogger::class )
			)
		);

		$container->singleton(
			CaptureController::class,
			static fn ( Container $c ): CaptureController => new CaptureController(
				$c->get( SessionService::class ),
				$c->get( RateLimiter::class ),
				$c->get( LeadCaptureService::class ),
				$c->get( VisitorService::class ),
				$c->get( WidgetConfig::class ),
				$c->get( ConversationRepositoryInterface::class ),
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
				// The pipeline controller registers first: its routes are
				// literal paths under /admin/leads and the lead controller's
				// are uuid patterns. WordPress matches in registration order,
				// so the other way round would read "stages" as an id.
				$server->add( $this->container->get( PipelineController::class ) );
				$server->add( $this->container->get( LeadController::class ) );
				$server->add( $this->container->get( CaptureController::class ) );
			}
		);

		add_action(
			'hiveclerk/jobs/register',
			function ( JobRegistry $jobs ): void {
				$jobs->add( $this->container->get( ScoreLeadJob::class ) );
			}
		);

		/*
		 * Capture listens rather than being called. ChatService must not
		 * know that leads exist — it is the layer that spends money and its
		 * ordering is the product's cost model, not a place to add a
		 * feature. A listener that threw would take a visitor's reply with
		 * it, so the service it calls returns quietly instead.
		 */
		add_action(
			'hiveclerk/chat/replied',
			function ( $outcome, Conversation $conversation, Agent $agent ): void {
				unset( $outcome );

				$this->container->get( LeadCaptureService::class )->onReply( $conversation, $agent );
			},
			10,
			3
		);
	}

	/**
	 * Capabilities this module requires.
	 *
	 * @return array<int, string>
	 */
	public function capabilities(): array {
		return array( Capabilities::MANAGE_LEADS );
	}
}
