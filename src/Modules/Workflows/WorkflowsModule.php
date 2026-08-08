<?php
/**
 * Workflows module.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows;

use Hiveclerk\Api\RestServer;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Container\Container;
use Hiveclerk\Core\Licence\LicenceGate;
use Hiveclerk\Core\Module\AbstractModule;
use Hiveclerk\Core\Queue\JobRegistry;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Email\SequenceRepositoryInterface;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;
use Hiveclerk\Domain\Workflow\RunLogRepositoryInterface;
use Hiveclerk\Domain\Workflow\TriggerEvent;
use Hiveclerk\Domain\Workflow\WorkflowRepositoryInterface;
use Hiveclerk\Domain\Workflow\WorkflowRunRepositoryInterface;
use Hiveclerk\Modules\Email\Services\EnrolmentService;
use Hiveclerk\Modules\Integrations\Services\SyncService;
use Hiveclerk\Modules\Integrations\Services\WebhookDispatcher;
use Hiveclerk\Modules\Leads\Services\LeadService;
use Hiveclerk\Modules\Leads\Services\ScoringService;
use Hiveclerk\Modules\Workflows\Actions\AddNoteAction;
use Hiveclerk\Modules\Workflows\Actions\AdjustScoreAction;
use Hiveclerk\Modules\Workflows\Actions\EnrolSequenceAction;
use Hiveclerk\Modules\Workflows\Actions\NotifyAdminAction;
use Hiveclerk\Modules\Workflows\Actions\SetStageAction;
use Hiveclerk\Modules\Workflows\Actions\SyncCrmAction;
use Hiveclerk\Modules\Workflows\Actions\WebhookAction;
use Hiveclerk\Modules\Workflows\Http\WorkflowController;
use Hiveclerk\Modules\Workflows\Jobs\WorkflowTickJob;
use Hiveclerk\Modules\Workflows\Services\ActionRegistry;
use Hiveclerk\Modules\Workflows\Services\ConditionEvaluator;
use Hiveclerk\Modules\Workflows\Services\ContextBuilder;
use Hiveclerk\Modules\Workflows\Services\TriggerRouter;
use Hiveclerk\Modules\Workflows\Services\WorkflowEngine;
use Hiveclerk\Modules\Workflows\Services\WorkflowJanitor;
use Hiveclerk\Modules\Workflows\Services\WorkflowService;
use Hiveclerk\Modules\Workflows\Services\WorkflowSimulator;

/**
 * Triggers, conditions, actions, delays and branching (FR-WFL-01…07).
 *
 * ## It listens to the same events every other module listens to
 *
 * Nothing was changed in Leads, Chat or Email to make this work, which
 * was the point of the event bus from the first architecture document:
 * "this is what lets the workflow builder subscribe to everything without
 * modifying existing modules". The claim is now tested rather than
 * asserted — every trigger here is an `add_action` on a hook that already
 * existed.
 *
 * ## Actions are registered only when their module is present
 *
 * Each handler is bound behind a container check, so a site that filtered
 * out Integrations has no CRM action rather than a fatal on the first run
 * that reaches one. The builder greys the node out and the validator
 * refuses to activate a workflow that needs it — the failure is visible
 * on the screen where it can be fixed, not in a job log at 3am.
 *
 * ## Registered last in the plugin
 *
 * It resolves services from four other modules and is called by none of
 * them, so it is the leaf of the dependency graph. A site can filter this
 * whole module out and everything else carries on.
 */
final class WorkflowsModule extends AbstractModule {

	/**
	 * Machine identifier.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'workflows';
	}

	/**
	 * Bind services.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {
		parent::register( $container );

		$container->singleton( ConditionEvaluator::class, static fn (): ConditionEvaluator => new ConditionEvaluator() );

		$container->singleton(
			ContextBuilder::class,
			static fn ( Container $c ): ContextBuilder => new ContextBuilder(
				$c->get( LeadRepositoryInterface::class ),
				$c->get( LeadStageRepositoryInterface::class ),
				$c->get( ConversationRepositoryInterface::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			ActionRegistry::class,
			fn ( Container $c ): ActionRegistry => $this->buildRegistry( $c )
		);

		$container->singleton(
			WorkflowEngine::class,
			static fn ( Container $c ): WorkflowEngine => new WorkflowEngine(
				$c->get( WorkflowRunRepositoryInterface::class ),
				$c->get( WorkflowRepositoryInterface::class ),
				$c->get( RunLogRepositoryInterface::class ),
				$c->get( ContextBuilder::class ),
				$c->get( ConditionEvaluator::class ),
				$c->get( ActionRegistry::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			TriggerRouter::class,
			static fn ( Container $c ): TriggerRouter => new TriggerRouter(
				$c->get( WorkflowRepositoryInterface::class ),
				$c->get( WorkflowRunRepositoryInterface::class ),
				$c->get( LeadRepositoryInterface::class ),
				$c->get( ContextBuilder::class ),
				$c->get( WorkflowEngine::class ),
				$c->get( QueueInterface::class ),
				$c->get( LicenceGate::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			WorkflowJanitor::class,
			static fn ( Container $c ): WorkflowJanitor => new WorkflowJanitor(
				$c->get( WorkflowRunRepositoryInterface::class ),
				$c->get( RunLogRepositoryInterface::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			WorkflowService::class,
			static fn ( Container $c ): WorkflowService => new WorkflowService(
				$c->get( WorkflowRepositoryInterface::class ),
				$c->get( WorkflowRunRepositoryInterface::class ),
				$c->get( ActionRegistry::class ),
				$c->get( AuditLogger::class ),
				$c->get( ClockInterface::class )
			)
		);

		$container->singleton(
			WorkflowSimulator::class,
			static fn ( Container $c ): WorkflowSimulator => new WorkflowSimulator(
				$c->get( ContextBuilder::class ),
				$c->get( ConditionEvaluator::class ),
				$c->get( ActionRegistry::class )
			)
		);

		$container->singleton(
			WorkflowTickJob::class,
			static fn ( Container $c ): WorkflowTickJob => new WorkflowTickJob(
				$c->get( WorkflowEngine::class ),
				$c->get( TriggerRouter::class ),
				$c->get( WorkflowJanitor::class ),
				$c->get( QueueInterface::class )
			)
		);

		$container->singleton(
			WorkflowController::class,
			static fn ( Container $c ): WorkflowController => new WorkflowController(
				$c->get( WorkflowService::class ),
				$c->get( WorkflowRepositoryInterface::class ),
				$c->get( WorkflowRunRepositoryInterface::class ),
				$c->get( RunLogRepositoryInterface::class ),
				$c->get( WorkflowSimulator::class ),
				$c->get( ActionRegistry::class ),
				$c->get( LeadStageRepositoryInterface::class ),
				$c->get( SequenceRepositoryInterface::class ),
				$c->get( LeadRepositoryInterface::class ),
				$c->get( LicenceGate::class )
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
				$server->add( $this->container->get( WorkflowController::class ) );
			}
		);

		add_action(
			'hiveclerk/jobs/register',
			function ( JobRegistry $jobs ): void {
				$jobs->add( $this->container->get( WorkflowTickJob::class ) );

				// Scheduled at boot rather than on activation, and
				// idempotently. A site that upgraded into this version was
				// never activated again, and a workflow that opens runs
				// nothing ever advances is the hardest kind of bug to
				// notice: everything looks configured.
				$this->container->get( QueueInterface::class )->scheduleRecurring(
					WorkflowTickJob::INTERVAL,
					WorkflowTickJob::hook()
				);
			}
		);

		add_action(
			'hiveclerk/lead/captured',
			function ( Lead $lead ): void {
				$this->router()->onLead( TriggerEvent::LeadCaptured, $lead );
			}
		);

		add_action(
			'hiveclerk/lead/qualified',
			function ( Lead $lead ): void {
				$this->router()->onLead( TriggerEvent::LeadQualified, $lead );
			}
		);

		add_action(
			'hiveclerk/lead/stage_changed',
			function ( Lead $lead, ?int $stageId ): void {
				$this->router()->onLead( TriggerEvent::LeadStageChanged, $lead, $stageId );
			},
			10,
			2
		);

		add_action(
			'hiveclerk/conversation/handoff_requested',
			function ( Conversation $conversation, mixed $agent, ?string $reason ): void {
				unset( $agent );

				$this->router()->onConversation(
					TriggerEvent::HandoffRequested,
					$conversation,
					$reason
				);
			},
			10,
			3
		);
	}

	/**
	 * Build the action registry from whatever this install has.
	 *
	 * @param Container $c Container.
	 * @return ActionRegistry
	 */
	private function buildRegistry( Container $c ): ActionRegistry {
		$registry = new ActionRegistry();
		$leads    = $c->get( LeadRepositoryInterface::class );

		$registry->add( new NotifyAdminAction() );

		if ( $c->has( LeadService::class ) ) {
			$registry->add(
				new SetStageAction( $leads, $c->get( LeadService::class ), $c->get( LeadStageRepositoryInterface::class ) )
			);
			$registry->add( new AddNoteAction( $leads, $c->get( LeadService::class ) ) );
		}

		if ( $c->has( ScoringService::class ) ) {
			$registry->add( new AdjustScoreAction( $leads, $c->get( ScoringService::class ) ) );
		}

		if ( $c->has( EnrolmentService::class ) ) {
			$registry->add(
				new EnrolSequenceAction(
					$leads,
					$c->get( EnrolmentService::class ),
					$c->get( SequenceRepositoryInterface::class )
				)
			);
		}

		if ( $c->has( SyncService::class ) ) {
			$registry->add( new SyncCrmAction( $leads, $c->get( SyncService::class ) ) );
		}

		if ( $c->has( WebhookDispatcher::class ) ) {
			$registry->add( new WebhookAction( $c->get( WebhookDispatcher::class ), $leads ) );
		}

		/**
		 * Register additional workflow actions.
		 *
		 * A handler whose type is not a known {@see \Hiveclerk\Domain\Workflow\ActionType}
		 * can never be reached by the graph validator, so extending the
		 * set means extending the enum too — deliberately, because the
		 * builder has to be able to name what a node does.
		 *
		 * @param ActionRegistry $registry Action registry.
		 * @param Container      $c        Container.
		 */
		do_action( 'hiveclerk/workflows/actions', $registry, $c );

		return $registry;
	}

	/**
	 * The router, resolved late.
	 *
	 * @return TriggerRouter
	 */
	private function router(): TriggerRouter {
		return $this->container->get( TriggerRouter::class );
	}

	/**
	 * Capabilities this module requires.
	 *
	 * @return array<int, string>
	 */
	public function capabilities(): array {
		return array( Capabilities::MANAGE_WORKFLOWS );
	}
}
