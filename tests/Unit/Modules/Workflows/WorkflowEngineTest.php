<?php
/**
 * Engine tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Workflows;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Domain\Workflow\ActionResult;
use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\ConditionField;
use Hiveclerk\Domain\Workflow\ConditionOperator;
use Hiveclerk\Domain\Workflow\NodeType;
use Hiveclerk\Domain\Workflow\RunStatus;
use Hiveclerk\Domain\Workflow\SubjectType;
use Hiveclerk\Domain\Workflow\Workflow;
use Hiveclerk\Domain\Workflow\WorkflowGraph;
use Hiveclerk\Domain\Workflow\WorkflowNode;
use Hiveclerk\Domain\Workflow\WorkflowRun;
use Hiveclerk\Domain\Workflow\WorkflowStatus;
use Hiveclerk\Modules\Workflows\Services\ActionRegistry;
use Hiveclerk\Modules\Workflows\Services\ConditionEvaluator;
use Hiveclerk\Modules\Workflows\Services\ContextBuilder;
use Hiveclerk\Modules\Workflows\Services\WorkflowEngine;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use Hiveclerk\Tests\Support\Chat\InMemoryConversations;
use Hiveclerk\Tests\Support\Leads\InMemoryLeads;
use Hiveclerk\Tests\Support\Leads\InMemoryStages;
use Hiveclerk\Tests\Support\Workflows\InMemoryRunLog;
use Hiveclerk\Tests\Support\Workflows\InMemoryRuns;
use Hiveclerk\Tests\Support\Workflows\InMemoryWorkflows;
use Hiveclerk\Tests\Support\Workflows\RecordingAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a run actually does, step by step.
 *
 * The engine is the one part of this feature that runs unattended against
 * a customer's data, so these tests are written around the failures that
 * would be invisible until a recipient complains: an action running twice
 * because a delay did not park the run, a paused workflow carrying on, a
 * deleted lead taking a run down with a red error rather than a quiet
 * cancellation.
 *
 * @internal
 */
#[CoversClass( WorkflowEngine::class )]
final class WorkflowEngineTest extends TestCase {

	private InMemoryWorkflows $workflows;

	private InMemoryRuns $runs;

	private InMemoryRunLog $log;

	private InMemoryLeads $leads;

	private ActionRegistry $actions;

	private RecordingAction $note;

	private FrozenClock $clock;

	private WorkflowEngine $engine;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );

		$this->clock = new FrozenClock(
			new DateTimeImmutable( '2026-08-09 09:00:00', new DateTimeZone( 'UTC' ) )
		);

		$this->workflows = new InMemoryWorkflows();
		$this->runs      = new InMemoryRuns();
		$this->log       = new InMemoryRunLog();
		$this->leads     = new InMemoryLeads();

		$this->note    = new RecordingAction( ActionType::AddNote );
		$this->actions = new ActionRegistry();
		$this->actions->add( $this->note );

		$this->engine = new WorkflowEngine(
			$this->runs,
			$this->workflows,
			$this->log,
			new ContextBuilder(
				$this->leads,
				new InMemoryStages(),
				new InMemoryConversations(),
				$this->clock
			),
			new ConditionEvaluator(),
			$this->actions,
			$this->clock
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testARunWalksStraightThroughActionsInOnePass(): void {
		$workflow = $this->workflow(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'one' ),
				'one'                => $this->action( 'one', 'two' ),
				'two'                => $this->action( 'two' ),
			)
		);

		$run = $this->openRun( $workflow );

		$this->engine->advance( $run );

		// Two actions, one tick. A workflow with no delay in it should not
		// take ten minutes to do two things.
		self::assertSame( 2, $this->note->callCount() );
		self::assertSame( RunStatus::Completed, $run->status );
	}

	public function testADelayParksTheRunRatherThanSleeping(): void {
		$workflow = $this->workflow(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'wait' ),
				'wait'               => new WorkflowNode( 'wait', NodeType::Delay, array( 'minutes' => 120 ), 'act' ),
				'act'                => $this->action( 'act' ),
			)
		);

		$run = $this->openRun( $workflow );

		$this->engine->advance( $run );

		self::assertSame( 0, $this->note->callCount() );
		self::assertSame( RunStatus::Waiting, $run->status );
		self::assertSame( 'act', $run->currentNode );
		self::assertSame( '2026-08-09 11:00:00', $run->resumeAt?->format( 'Y-m-d H:i:s' ) );
	}

	public function testTheRunResumesIntoTheStepAfterTheWait(): void {
		$workflow = $this->workflow(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'wait' ),
				'wait'               => new WorkflowNode( 'wait', NodeType::Delay, array( 'minutes' => 120 ), 'act' ),
				'act'                => $this->action( 'act' ),
			)
		);

		$run = $this->openRun( $workflow );
		$this->engine->advance( $run );

		$this->clock->advance( 3 * 3600 );
		$this->engine->advance( $run );

		self::assertSame( 1, $this->note->callCount() );
		self::assertSame( RunStatus::Completed, $run->status );
	}

	public function testAConditionSendsTheRunDownTheBranchItMatched(): void {
		$yes = new RecordingAction( ActionType::SetStage );
		$this->actions->add( $yes );

		$workflow = $this->workflow(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'check' ),
				'check'              => new WorkflowNode(
					'check',
					NodeType::Condition,
					array(
						'field'    => ConditionField::Score->value,
						'operator' => ConditionOperator::GreaterThan->value,
						'value'    => '60',
					),
					null,
					'strong',
					'weak'
				),
				'strong'             => new WorkflowNode(
					'strong',
					NodeType::Action,
					array( 'action' => ActionType::SetStage->value )
				),
				'weak'               => $this->action( 'weak' ),
			)
		);

		$this->engine->advance( $this->openRun( $workflow, $this->lead( 80 ) ) );

		self::assertSame( 1, $yes->callCount() );
		self::assertSame( 0, $this->note->callCount() );
	}

	public function testAConditionRecordsTheValueItComparedInTheRunLog(): void {
		$workflow = $this->workflow(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'check' ),
				'check'              => new WorkflowNode(
					'check',
					NodeType::Condition,
					array(
						'field'    => ConditionField::Score->value,
						'operator' => ConditionOperator::GreaterThan->value,
						'value'    => '60',
					),
					null,
					'act'
				),
				'act'                => $this->action( 'act' ),
			)
		);

		$this->engine->advance( $this->openRun( $workflow, $this->lead( 41 ) ) );

		self::assertNotEmpty(
			array_filter(
				$this->log->details(),
				static fn ( string $detail ): bool => str_contains( $detail, '41' )
			)
		);
	}

	public function testAPausedWorkflowLeavesItsRunsExactlyWhereTheyStand(): void {
		$workflow = $this->workflow(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'act' ),
				'act'                => $this->action( 'act' ),
			)
		);

		$workflow->status = WorkflowStatus::Paused;
		$this->workflows->save( $workflow );

		$run = $this->openRun( $workflow );

		$this->engine->advance( $run );

		self::assertSame( 0, $this->note->callCount() );
		self::assertSame( RunStatus::Pending, $run->status );
		self::assertSame( 'act', $run->currentNode );
	}

	public function testARunWhoseLeadWasErasedIsCancelledRatherThanFailed(): void {
		$workflow = $this->workflow(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'act' ),
				'act'                => $this->action( 'act' ),
			)
		);

		$run = new WorkflowRun(
			id: null,
			workflowId: (int) $workflow->id,
			subjectType: SubjectType::Lead,
			subjectId: 999,
			currentNode: 'act',
			resumeAt: $this->clock->now(),
			startedAt: $this->clock->now(),
		);

		$this->runs->save( $run );
		$this->engine->advance( $run );

		// A privacy erasure is the system working. A red "failed" row for
		// it would put an operator on a hunt for a bug that is a legal
		// obligation being honoured.
		self::assertSame( RunStatus::Cancelled, $run->status );
	}

	public function testAnActionThatSkipsDoesNotStopTheRun(): void {
		$this->note->willReturn( ActionResult::skipped( 'Already unsubscribed.' ) );

		$second = new RecordingAction( ActionType::SetStage );
		$this->actions->add( $second );

		$workflow = $this->workflow(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'one' ),
				'one'                => $this->action( 'one', 'two' ),
				'two'                => new WorkflowNode(
					'two',
					NodeType::Action,
					array( 'action' => ActionType::SetStage->value )
				),
			)
		);

		$run = $this->openRun( $workflow );
		$this->engine->advance( $run );

		self::assertSame( 1, $second->callCount() );
		self::assertSame( RunStatus::Completed, $run->status );
	}

	public function testAnActionThatFailsStopsTheRunAndSaysWhy(): void {
		$this->note->willReturn( ActionResult::failed( 'The CRM refused it.' ) );

		$workflow = $this->workflow(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'act' ),
				'act'                => $this->action( 'act' ),
			)
		);

		$run = $this->openRun( $workflow );
		$this->engine->advance( $run );

		self::assertSame( RunStatus::Failed, $run->status );
		self::assertSame( 'The CRM refused it.', $run->error );
	}

	public function testAnActionAskingToRetryComesBackLaterRatherThanFailing(): void {
		$this->note->willReturn( ActionResult::retry( 'Mail is down.' ) );

		$workflow = $this->workflow(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'act' ),
				'act'                => $this->action( 'act' ),
			)
		);

		$run = $this->openRun( $workflow );
		$this->engine->advance( $run );

		self::assertSame( RunStatus::Waiting, $run->status );
		self::assertSame( 'act', $run->currentNode );
		self::assertSame( 1, $run->attempts );
	}

	public function testARetryGivesUpRatherThanRunningForEver(): void {
		$this->note->willReturn( ActionResult::retry( 'Mail is down.' ) );

		$workflow = $this->workflow(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'act' ),
				'act'                => $this->action( 'act' ),
			)
		);

		$run = $this->openRun( $workflow );

		for ( $attempt = 0; $attempt <= WorkflowRun::MAX_ATTEMPTS; $attempt++ ) {
			$this->clock->advance( WorkflowEngine::RETRY_MINUTES * 60 );
			$this->engine->advance( $run );
		}

		self::assertSame( RunStatus::Failed, $run->status );
	}

	public function testAnActionThisSiteCannotPerformIsSkippedRatherThanFatal(): void {
		// The email module filtered out. The step is skipped with a reason
		// and the rest of the workflow still runs, rather than every run on
		// the site turning red.
		$workflow = $this->workflow(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'gone' ),
				'gone'               => new WorkflowNode(
					'gone',
					NodeType::Action,
					array( 'action' => ActionType::EnrolSequence->value ),
					'act'
				),
				'act'                => $this->action( 'act' ),
			)
		);

		$run = $this->openRun( $workflow );
		$this->engine->advance( $run );

		self::assertSame( 1, $this->note->callCount() );
		self::assertSame( RunStatus::Completed, $run->status );
	}

	public function testTheTickAdvancesEveryDueRunAndReportsWhatIsLeft(): void {
		$workflow = $this->workflow(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'act' ),
				'act'                => $this->action( 'act' ),
			)
		);

		foreach ( range( 1, 3 ) as $id ) {
			$this->leads->save( $this->lead( 50, $id ) );

			$this->runs->save(
				new WorkflowRun(
					id: null,
					workflowId: (int) $workflow->id,
					subjectId: $id,
					currentNode: 'act',
					resumeAt: $this->clock->now(),
					startedAt: $this->clock->now(),
				)
			);
		}

		$result = $this->engine->tick();

		self::assertSame( 3, $result['advanced'] );
		self::assertSame( 3, $result['finished'] );
		self::assertSame( 0, $result['remaining'] );
	}

	/**
	 * Store a workflow.
	 *
	 * @param array<string, WorkflowNode> $nodes Nodes.
	 * @return Workflow
	 */
	private function workflow( array $nodes ): Workflow {
		return $this->workflows->save(
			new Workflow(
				id: null,
				uuid: Uuid::generate(),
				name: 'Test workflow',
				status: WorkflowStatus::Active,
				graph: new WorkflowGraph( $nodes ),
			)
		);
	}

	/**
	 * Open a run against a lead.
	 *
	 * @param Workflow  $workflow Workflow.
	 * @param Lead|null $lead     Lead, created by default.
	 * @return WorkflowRun
	 */
	private function openRun( Workflow $workflow, ?Lead $lead = null ): WorkflowRun {
		$subject = $this->leads->save( $lead ?? $this->lead( 50 ) );

		return $this->runs->save(
			new WorkflowRun(
				id: null,
				workflowId: (int) $workflow->id,
				subjectType: SubjectType::Lead,
				subjectId: $subject->id,
				currentNode: $workflow->graph->entry(),
				resumeAt: $this->clock->now(),
				startedAt: $this->clock->now(),
			)
		);
	}

	/**
	 * A lead with a score.
	 *
	 * @param int      $score Score.
	 * @param int|null $id    Storage id.
	 * @return Lead
	 */
	private function lead( int $score, ?int $id = null ): Lead {
		return new Lead(
			id: $id,
			uuid: Uuid::generate(),
			email: 'lead@example.test',
			score: $score,
			createdAt: $this->clock->now(),
		);
	}

	/**
	 * A trigger node.
	 *
	 * @param string $next First step.
	 * @return WorkflowNode
	 */
	private function trigger( string $next ): WorkflowNode {
		return new WorkflowNode( WorkflowGraph::ENTRY, NodeType::Trigger, array(), $next );
	}

	/**
	 * A note action.
	 *
	 * @param string      $id   Node id.
	 * @param string|null $next Next node.
	 * @return WorkflowNode
	 */
	private function action( string $id, ?string $next = null ): WorkflowNode {
		return new WorkflowNode(
			$id,
			NodeType::Action,
			array( 'action' => ActionType::AddNote->value ),
			$next
		);
	}
}
