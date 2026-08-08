<?php
/**
 * Graph validation tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Domain\Workflow;

use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\ConditionField;
use Hiveclerk\Domain\Workflow\ConditionOperator;
use Hiveclerk\Domain\Workflow\GraphError;
use Hiveclerk\Domain\Workflow\NodeType;
use Hiveclerk\Domain\Workflow\SubjectType;
use Hiveclerk\Domain\Workflow\WorkflowGraph;
use Hiveclerk\Domain\Workflow\WorkflowNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The gate that stands between an operator and a runaway automation.
 *
 * Every case here is a graph the engine must never be handed. The engine
 * walks nodes inside a background job against live customer data, and it
 * is written to assume these answers — an edge that points nowhere or a
 * loop back to the top would be discovered halfway through a run, with an
 * email already sent and no sensible place to resume.
 *
 * The cycle test is the one that matters most. A loop is not a slow
 * workflow; it is a workflow that sends mail until somebody notices.
 *
 * @internal
 */
#[CoversClass( WorkflowGraph::class )]
final class WorkflowGraphTest extends TestCase {

	public function testAWellFormedGraphHasNoErrors(): void {
		$graph = $this->graph(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'wait' ),
				'wait'               => new WorkflowNode( 'wait', NodeType::Delay, array( 'minutes' => 60 ), 'act' ),
				'act'                => $this->action( 'act' ),
			)
		);

		self::assertSame( array(), $graph->errors() );
		self::assertTrue( $graph->isValid() );
	}

	public function testACycleIsRefused(): void {
		$graph = $this->graph(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'act' ),
				'act'                => new WorkflowNode(
					'act',
					NodeType::Action,
					array( 'action' => ActionType::AddNote->value ),
					'wait'
				),
				// Straight back to the action it just came from. Without
				// this check the run would note the lead, wait, note it
				// again, for ever.
				'wait'               => new WorkflowNode( 'wait', NodeType::Delay, array( 'minutes' => 60 ), 'act' ),
			)
		);

		self::assertContains( GraphError::CYCLE, $this->codes( $graph ) );
	}

	public function testAnEdgeToANodeThatWasDeletedIsRefused(): void {
		$graph = $this->graph(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'act' ),
				'act'                => new WorkflowNode(
					'act',
					NodeType::Action,
					array( 'action' => ActionType::AddNote->value ),
					'gone'
				),
			)
		);

		self::assertContains( GraphError::DANGLING_EDGE, $this->codes( $graph ) );
	}

	public function testANodeNothingLeadsToIsRefused(): void {
		$graph = $this->graph(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'act' ),
				'act'                => $this->action( 'act' ),
				// Reachable from nothing: an operator deleted the condition
				// above it and this chain was left floating.
				'orphan'             => $this->action( 'orphan' ),
			)
		);

		self::assertContains( GraphError::UNREACHABLE, $this->codes( $graph ) );
	}

	public function testAGraphThatDecidesButNeverActsIsRefused(): void {
		$graph = $this->graph(
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
					'wait'
				),
				'wait'               => new WorkflowNode( 'wait', NodeType::Delay, array( 'minutes' => 60 ) ),
			)
		);

		self::assertContains( GraphError::NO_ACTION, $this->codes( $graph ) );
	}

	public function testAConditionWithNothingToCompareIsRefused(): void {
		$graph = $this->graph(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'check' ),
				'check'              => new WorkflowNode(
					'check',
					NodeType::Condition,
					array(
						'field'    => ConditionField::Company->value,
						'operator' => ConditionOperator::Equals->value,
					),
					null,
					'act'
				),
				'act'                => $this->action( 'act' ),
			)
		);

		self::assertContains( GraphError::CONDITION_VALUE, $this->codes( $graph ) );
	}

	public function testAConditionNeedingNoValueIsAccepted(): void {
		$graph = $this->graph(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'check' ),
				'check'              => new WorkflowNode(
					'check',
					NodeType::Condition,
					array(
						'field'    => ConditionField::Email->value,
						'operator' => ConditionOperator::IsSet->value,
					),
					null,
					'act'
				),
				'act'                => $this->action( 'act' ),
			)
		);

		self::assertNotContains( GraphError::CONDITION_VALUE, $this->codes( $graph ) );
	}

	public function testAWaitOfNoTimeIsRefused(): void {
		$graph = $this->graph(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'wait' ),
				'wait'               => new WorkflowNode( 'wait', NodeType::Delay, array( 'minutes' => 0 ), 'act' ),
				'act'                => $this->action( 'act' ),
			)
		);

		self::assertContains( GraphError::DELAY_ZERO, $this->codes( $graph ) );
	}

	public function testALeadActionOnAConversationTriggerIsRefused(): void {
		$graph = $this->graph(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'act' ),
				// Moving a pipeline stage needs a lead, and a handoff
				// request does not have one yet.
				'act'                => $this->action( 'act', ActionType::SetStage ),
			)
		);

		self::assertContains(
			GraphError::ACTION_SUBJECT,
			$this->codes( $graph, SubjectType::Conversation )
		);
	}

	public function testAnActionAvailableToBothSubjectsIsAcceptedOnAConversation(): void {
		$graph = $this->graph(
			array(
				WorkflowGraph::ENTRY => $this->trigger( 'act' ),
				'act'                => $this->action( 'act', ActionType::NotifyAdmin ),
			)
		);

		self::assertSame( array(), $graph->errors( SubjectType::Conversation ) );
	}

	public function testMoreStepsThanAnybodyCanReadIsRefused(): void {
		$nodes = array( WorkflowGraph::ENTRY => $this->trigger( 'n0' ) );

		for ( $i = 0; $i < WorkflowGraph::MAX_NODES + 2; $i++ ) {
			$next = $i < WorkflowGraph::MAX_NODES + 1 ? 'n' . ( $i + 1 ) : null;

			$nodes[ 'n' . $i ] = new WorkflowNode(
				'n' . $i,
				NodeType::Action,
				array( 'action' => ActionType::AddNote->value ),
				$next
			);
		}

		self::assertContains( GraphError::TOO_MANY_NODES, $this->codes( $this->graph( $nodes ) ) );
	}

	public function testStoredGraphsSurviveARoundTrip(): void {
		$graph = $this->graph(
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
					'act',
					'other'
				),
				'act'                => $this->action( 'act' ),
				'other'              => $this->action( 'other' ),
			)
		);

		$restored = WorkflowGraph::fromArray( $graph->toArray() );

		self::assertSame( $graph->toArray(), $restored->toArray() );
		self::assertSame( 'act', $restored->node( 'check' )?->successor( true ) );
		self::assertSame( 'other', $restored->node( 'check' )?->successor( false ) );
	}

	public function testANodeTypeThisVersionDoesNotKnowIsDroppedRatherThanFatal(): void {
		// A row written by a newer version of the plugin, read by an older
		// one after a rollback. The step is lost and validation reports the
		// hole; the admin screen still renders.
		$restored = WorkflowGraph::fromArray(
			array(
				WorkflowGraph::ENTRY => array(
					'type' => 'trigger',
					'next' => 'weird',
				),
				'weird'              => array(
					'type' => 'quantum_fork',
					'next' => null,
				),
			)
		);

		self::assertNull( $restored->node( 'weird' ) );
		self::assertContains( GraphError::DANGLING_EDGE, $this->codes( $restored ) );
	}

	/**
	 * Build a graph.
	 *
	 * @param array<string, WorkflowNode> $nodes Nodes.
	 * @return WorkflowGraph
	 */
	private function graph( array $nodes ): WorkflowGraph {
		return new WorkflowGraph( $nodes );
	}

	/**
	 * A trigger node pointing somewhere.
	 *
	 * @param string $next First step.
	 * @return WorkflowNode
	 */
	private function trigger( string $next ): WorkflowNode {
		return new WorkflowNode( WorkflowGraph::ENTRY, NodeType::Trigger, array(), $next );
	}

	/**
	 * A terminal action node.
	 *
	 * @param string          $id   Node id.
	 * @param ActionType|null $type Action, defaulting to a note.
	 * @return WorkflowNode
	 */
	private function action( string $id, ?ActionType $type = null ): WorkflowNode {
		return new WorkflowNode(
			$id,
			NodeType::Action,
			array( 'action' => ( $type ?? ActionType::AddNote )->value )
		);
	}

	/**
	 * The error codes a graph reports.
	 *
	 * @param WorkflowGraph $graph   Graph.
	 * @param SubjectType   $subject Subject kind.
	 * @return array<int, string>
	 */
	private function codes( WorkflowGraph $graph, SubjectType $subject = SubjectType::Lead ): array {
		return array_map(
			static fn ( GraphError $error ): string => $error->code,
			$graph->errors( $subject )
		);
	}
}
