<?php
/**
 * Trigger routing tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Workflows;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Licence\LicenceClient;
use Hiveclerk\Core\Licence\LicenceGate;
use Hiveclerk\Core\Licence\LicenceService;
use Hiveclerk\Core\Support\Encryptor;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\NodeType;
use Hiveclerk\Domain\Workflow\TriggerEvent;
use Hiveclerk\Domain\Workflow\Workflow;
use Hiveclerk\Domain\Workflow\WorkflowGraph;
use Hiveclerk\Domain\Workflow\WorkflowNode;
use Hiveclerk\Domain\Workflow\WorkflowStatus;
use Hiveclerk\Modules\Workflows\Jobs\WorkflowTickJob;
use Hiveclerk\Modules\Workflows\Services\ActionRegistry;
use Hiveclerk\Modules\Workflows\Services\ConditionEvaluator;
use Hiveclerk\Modules\Workflows\Services\ContextBuilder;
use Hiveclerk\Modules\Workflows\Services\TriggerRouter;
use Hiveclerk\Modules\Workflows\Services\WorkflowEngine;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use Hiveclerk\Tests\Support\Chat\InMemoryAudit;
use Hiveclerk\Tests\Support\Chat\InMemoryConversations;
use Hiveclerk\Tests\Support\Leads\InMemoryLeads;
use Hiveclerk\Tests\Support\Leads\InMemoryStages;
use Hiveclerk\Tests\Support\Leads\NullQueue;
use Hiveclerk\Tests\Support\Workflows\InMemoryRunLog;
use Hiveclerk\Tests\Support\Workflows\InMemoryRuns;
use Hiveclerk\Tests\Support\Workflows\InMemoryWorkflows;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The guards between an event and a run.
 *
 * Each of these prevents a specific way an automation runs away with a
 * customer's mailing list: the same lead entering the same workflow four
 * times because their stage changed four times, two requests in the same
 * second both opening a run, or a workflow whose action fires the event a
 * second workflow is listening for.
 *
 * @internal
 */
#[CoversClass( TriggerRouter::class )]
final class TriggerRouterTest extends TestCase {

	private InMemoryWorkflows $workflows;

	private InMemoryRuns $runs;

	private InMemoryLeads $leads;

	private NullQueue $queue;

	private FrozenClock $clock;

	private TriggerRouter $router;

	/**
	 * Whether the licence in force includes workflows.
	 *
	 * Read by the `apply_filters` stub rather than by doubling the gate,
	 * which is final. The filter is the gate's own documented seam, so the
	 * test drives the real class down a path production also has.
	 *
	 * @var bool
	 */
	private bool $entitled = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->entitled = true;

		Functions\stubs(
			array(
				'__'                  => static fn ( string $text ): string => $text,
				'do_action'           => null,
				'get_option'          => false,
				'update_option'       => true,
				'delete_option'       => true,
				'sanitize_text_field' => static fn ( string $value ): string => $value,
				'home_url'            => 'https://example.test',
				'untrailingslashit'   => static fn ( string $value ): string => rtrim( $value, '/' ),
				'number_format_i18n'  => static fn ( $n ): string => (string) $n,
			)
		);

		Functions\when( 'apply_filters' )->alias(
			fn ( string $hook, $value ) => 'hiveclerk/licence/allows' === $hook
				? $this->entitled
				: $value
		);

		$this->clock = new FrozenClock(
			new DateTimeImmutable( '2026-08-09 09:00:00', new DateTimeZone( 'UTC' ) )
		);

		$this->workflows = new InMemoryWorkflows();
		$this->runs      = new InMemoryRuns();
		$this->leads     = new InMemoryLeads();
		$this->queue     = new NullQueue();

		$this->router = $this->buildRouter();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testALiveWorkflowOpensARunAndAsksForATick(): void {
		$this->workflow( TriggerEvent::LeadCaptured );

		$opened = $this->router->onLead( TriggerEvent::LeadCaptured, $this->lead() );

		self::assertSame( 1, $opened );
		self::assertCount( 1, $this->runs->rows );

		// The run is a row, not work done inline: the trigger fires inside
		// a visitor's request and the first node is often an HTTP call.
		self::assertTrue( $this->queue->isPending( WorkflowTickJob::hook() ) );
	}

	public function testADraftWorkflowIsNotStarted(): void {
		$workflow         = $this->workflow( TriggerEvent::LeadCaptured );
		$workflow->status = WorkflowStatus::Draft;
		$this->workflows->save( $workflow );

		self::assertSame( 0, $this->router->onLead( TriggerEvent::LeadCaptured, $this->lead() ) );
	}

	public function testTheSameLeadDoesNotGoThroughTwiceByDefault(): void {
		// The failure: a lead whose stage changes four times in an
		// afternoon receiving the same follow-up four times.
		$this->workflow( TriggerEvent::LeadStageChanged );

		$lead = $this->lead();

		$this->router->onLead( TriggerEvent::LeadStageChanged, $lead, 3 );
		$this->finishEveryRun();

		self::assertSame( 0, $this->router->onLead( TriggerEvent::LeadStageChanged, $lead, 3 ) );
	}

	public function testALeadCanGoThroughAgainWhenReEntryIsAllowed(): void {
		$workflow           = $this->workflow( TriggerEvent::LeadStageChanged );
		$workflow->runsOnce = false;
		$this->workflows->save( $workflow );

		$lead = $this->lead();

		$this->router->onLead( TriggerEvent::LeadStageChanged, $lead, 3 );
		$this->finishEveryRun();

		self::assertSame( 1, $this->router->onLead( TriggerEvent::LeadStageChanged, $lead, 3 ) );
	}

	public function testASecondRunIsRefusedWhileTheFirstIsStillOpen(): void {
		// Even with re-entry allowed. This is the guard the unique index
		// enforces in production, against two requests landing in the same
		// second and both finding no open run.
		$workflow           = $this->workflow( TriggerEvent::LeadStageChanged );
		$workflow->runsOnce = false;
		$this->workflows->save( $workflow );

		$lead = $this->lead();

		$this->router->onLead( TriggerEvent::LeadStageChanged, $lead, 3 );

		self::assertSame( 0, $this->router->onLead( TriggerEvent::LeadStageChanged, $lead, 3 ) );
		self::assertCount( 1, $this->runs->rows );
	}

	public function testAStageWorkflowIgnoresAMoveIntoAnotherStage(): void {
		$workflow                = $this->workflow( TriggerEvent::LeadStageChanged );
		$workflow->triggerConfig = array( 'stage_id' => 7 );
		$this->workflows->save( $workflow );

		self::assertSame( 0, $this->router->onLead( TriggerEvent::LeadStageChanged, $this->lead(), 3 ) );
		self::assertSame( 1, $this->router->onLead( TriggerEvent::LeadStageChanged, $this->lead(), 7 ) );
	}

	public function testALapsedLicenceStopsNewRuns(): void {
		$this->workflow( TriggerEvent::LeadCaptured );

		$this->entitled = false;

		self::assertSame( 0, $this->router->onLead( TriggerEvent::LeadCaptured, $this->lead() ) );
	}

	public function testAScheduledSweepOpensRunsForTheSegmentAndMovesItsClock(): void {
		$workflow                = $this->workflow( TriggerEvent::Schedule );
		$workflow->triggerConfig = array(
			'interval' => 1440,
			'segment'  => array( 'has_email' => true ),
		);
		$this->workflows->save( $workflow );

		foreach ( range( 1, 3 ) as $id ) {
			$this->leads->save( $this->lead( $id ) );
		}

		$opened = $this->router->sweepSchedules();

		self::assertSame( 3, $opened );

		// The clock moves whether or not anything matched. A sweep that
		// found nobody and left next_run_at alone would repeat its query
		// on every tick from then on.
		self::assertSame(
			'2026-08-10 09:00:00',
			$this->workflows->rows[ (int) $workflow->id ]->nextRunAt?->format( 'Y-m-d H:i:s' )
		);
	}

	public function testASweepThatMatchesNobodyStillMovesItsClock(): void {
		$workflow                = $this->workflow( TriggerEvent::Schedule );
		$workflow->triggerConfig = array( 'interval' => 60 );
		$this->workflows->save( $workflow );

		self::assertSame( 0, $this->router->sweepSchedules() );
		self::assertSame(
			'2026-08-09 10:00:00',
			$this->workflows->rows[ (int) $workflow->id ]->nextRunAt?->format( 'Y-m-d H:i:s' )
		);
	}

	/**
	 * Build a router over the in-memory repositories.
	 *
	 * @return TriggerRouter
	 */
	private function buildRouter(): TriggerRouter {
		$engine = new WorkflowEngine(
			$this->runs,
			$this->workflows,
			new InMemoryRunLog(),
			$this->context(),
			new ConditionEvaluator(),
			new ActionRegistry(),
			$this->clock
		);

		return new TriggerRouter(
			$this->workflows,
			$this->runs,
			$this->leads,
			$this->context(),
			$engine,
			$this->queue,
			$this->gate(),
			$this->clock
		);
	}

	/**
	 * A context builder over the in-memory repositories.
	 *
	 * @return ContextBuilder
	 */
	private function context(): ContextBuilder {
		return new ContextBuilder(
			$this->leads,
			new InMemoryStages(),
			new InMemoryConversations(),
			$this->clock
		);
	}

	/**
	 * A real licence gate, steered by the filter stub.
	 *
	 * @return LicenceGate
	 */
	private function gate(): LicenceGate {
		return new LicenceGate(
			new LicenceService(
				new LicenceClient(),
				new Encryptor(),
				new AuditLogger( new InMemoryAudit(), $this->clock ),
				$this->clock
			)
		);
	}

	/**
	 * Store a live workflow with one action.
	 *
	 * @param TriggerEvent $trigger Trigger.
	 * @return Workflow
	 */
	private function workflow( TriggerEvent $trigger ): Workflow {
		return $this->workflows->save(
			new Workflow(
				id: null,
				uuid: Uuid::generate(),
				name: 'Test workflow',
				status: WorkflowStatus::Active,
				trigger: $trigger,
				graph: new WorkflowGraph(
					array(
						WorkflowGraph::ENTRY => new WorkflowNode(
							WorkflowGraph::ENTRY,
							NodeType::Trigger,
							array(),
							'act'
						),
						'act'                => new WorkflowNode(
							'act',
							NodeType::Action,
							array( 'action' => ActionType::AddNote->value )
						),
					)
				),
			)
		);
	}

	/**
	 * A stored lead.
	 *
	 * @param int|null $id Storage id.
	 * @return Lead
	 */
	private function lead( ?int $id = null ): Lead {
		return $this->leads->save(
			new Lead(
				id: $id,
				uuid: Uuid::generate(),
				email: 'lead@example.test',
				createdAt: $this->clock->now(),
			)
		);
	}

	/**
	 * Close every open run, as the engine would.
	 *
	 * @return void
	 */
	private function finishEveryRun(): void {
		foreach ( $this->runs->rows as $run ) {
			$run->complete( $this->clock->now() );
		}
	}
}
