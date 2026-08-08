<?php
/**
 * The workflow engine.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Services;

use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Workflow\ActionHandlerInterface;
use Hiveclerk\Domain\Workflow\ActionResult;
use Hiveclerk\Domain\Workflow\NodeOutcome;
use Hiveclerk\Domain\Workflow\NodeType;
use Hiveclerk\Domain\Workflow\RunLogEntry;
use Hiveclerk\Domain\Workflow\RunLogRepositoryInterface;
use Hiveclerk\Domain\Workflow\Workflow;
use Hiveclerk\Domain\Workflow\WorkflowContext;
use Hiveclerk\Domain\Workflow\WorkflowGraph;
use Hiveclerk\Domain\Workflow\WorkflowNode;
use Hiveclerk\Domain\Workflow\WorkflowRepositoryInterface;
use Hiveclerk\Domain\Workflow\WorkflowRun;
use Hiveclerk\Domain\Workflow\WorkflowRunRepositoryInterface;

/**
 * Advances runs, one bounded batch at a time (FR-WFL-04).
 *
 * ## Everything is a job, including the first step
 *
 * A trigger opens a run and returns. It does not execute anything, even
 * though the first node is usually ready immediately — because the thing
 * that fired the trigger is a visitor's request, and the first node is
 * quite often an HTTP call to a CRM. Putting that inline would put a
 * third party's latency inside a conversation. The tick picks the run up
 * within five minutes, or sooner: the router enqueues an immediate tick.
 *
 * ## A run advances until it has a reason to stop
 *
 * Conditions and actions are fast, so a run walks straight through them
 * in a single tick — a lead captured at nine o'clock has its stage moved
 * and its note written in the same pass. It stops on a delay, on a
 * failure, at the end of the graph, or when it has taken more steps in
 * one pass than any sane graph needs. That last ceiling is per pass, not
 * per run: a graph with twelve nodes and no delay finishes in one; a
 * runaway one is stopped without stalling the batch.
 *
 * ## The context is rebuilt at every step
 *
 * {@see ContextBuilder} explains why. The short version: after a two-day
 * wait, "score is over 60" has to mean today's score.
 */
final class WorkflowEngine {

	/**
	 * Runs advanced per tick.
	 *
	 * Twenty-five, matching the sequence tick, and for the same reason:
	 * the plugin's rule is that no job runs longer than about twenty
	 * seconds, and the slowest thing a run can do is queue a CRM push.
	 */
	public const BATCH = 25;

	/**
	 * Nodes one run may enter in a single pass.
	 */
	public const STEPS_PER_PASS = 25;

	/**
	 * Minutes before a failed action is tried again.
	 */
	public const RETRY_MINUTES = 15;

	/**
	 * Whether a node is being executed right now.
	 *
	 * Read by {@see TriggerRouter}, which refuses to open new runs while
	 * this is true. A workflow that moves a lead's stage fires
	 * `hiveclerk/lead/stage_changed`, and a stage-triggered workflow
	 * listening for it would open a run that moves the stage again. The
	 * guard is crude and it is the right kind of crude: the failure it
	 * prevents is an unbounded loop spending the customer's money, and no
	 * legitimate design needs one workflow to trigger another synchronously
	 * from inside an action.
	 *
	 * @var bool
	 */
	private bool $executing = false;

	/**
	 * Construct.
	 *
	 * @param WorkflowRunRepositoryInterface $runs       Run storage.
	 * @param WorkflowRepositoryInterface    $workflows  Workflow storage.
	 * @param RunLogRepositoryInterface      $log        Run log.
	 * @param ContextBuilder                 $contexts   Context building.
	 * @param ConditionEvaluator             $conditions Condition evaluation.
	 * @param ActionRegistry                 $actions    Available actions.
	 * @param ClockInterface                 $clock      Clock.
	 */
	public function __construct(
		private readonly WorkflowRunRepositoryInterface $runs,
		private readonly WorkflowRepositoryInterface $workflows,
		private readonly RunLogRepositoryInterface $log,
		private readonly ContextBuilder $contexts,
		private readonly ConditionEvaluator $conditions,
		private readonly ActionRegistry $actions,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Whether an action is executing on this request.
	 *
	 * @return bool
	 */
	public function isExecuting(): bool {
		return $this->executing;
	}

	/**
	 * Advance every due run, up to the batch size.
	 *
	 * @return array{advanced: int, finished: int, remaining: int}
	 */
	public function tick(): array {
		$batch    = $this->runs->due( $this->clock->nowSql(), self::BATCH );
		$advanced = 0;
		$finished = 0;

		foreach ( $batch as $run ) {
			$result = $this->advance( $run );

			$advanced += $result['steps'] > 0 ? 1 : 0;
			$finished += $result['finished'] ? 1 : 0;
		}

		return array(
			'advanced'  => $advanced,
			'finished'  => $finished,
			'remaining' => $this->runs->countDue( $this->clock->nowSql() ),
		);
	}

	/**
	 * Walk one run as far as it will go this pass.
	 *
	 * @param WorkflowRun $run Run.
	 * @return array{steps: int, finished: bool}
	 */
	public function advance( WorkflowRun $run ): array {
		$workflow = $this->workflows->find( $run->workflowId );

		if ( null === $workflow || null !== $workflow->deletedAt ) {
			$this->stop( $run, __( 'The workflow was deleted.', 'hiveclerk' ), true );

			return array(
				'steps'    => 0,
				'finished' => true,
			);
		}

		if ( ! $workflow->status->advances() ) {
			// Paused. The run keeps its place and its resume time, so
			// resuming continues rather than replaying — the same contract
			// pausing a sequence has.
			return array(
				'steps'    => 0,
				'finished' => false,
			);
		}

		$context = $this->contexts->forRun( $run );

		if ( null === $context ) {
			$this->stop( $run, __( 'The lead this run was about no longer exists.', 'hiveclerk' ), true );

			return array(
				'steps'    => 0,
				'finished' => true,
			);
		}

		$context = $context->with(
			array(
				'workflow.name' => $workflow->name,
				'workflow.id'   => $workflow->id,
				'run.id'        => $run->id,
			)
		);

		return $this->walk( $run, $workflow, $context );
	}

	/**
	 * Enter nodes until the run has a reason to stop.
	 *
	 * @param WorkflowRun     $run      Run.
	 * @param Workflow        $workflow Workflow.
	 * @param WorkflowContext $context  What the run knows.
	 * @return array{steps: int, finished: bool}
	 */
	private function walk( WorkflowRun $run, Workflow $workflow, WorkflowContext $context ): array {
		$graph = $workflow->graph;
		$steps = 0;

		// A run that has never moved starts at the node after the trigger.
		$nodeId = $run->currentNode ?? $graph->entry();

		while ( $steps < self::STEPS_PER_PASS ) {
			if ( null === $nodeId ) {
				$this->finish( $run );

				return array(
					'steps'    => $steps,
					'finished' => true,
				);
			}

			if ( $run->isRunaway() ) {
				$this->stop( $run, __( 'This run took more steps than a workflow should need.', 'hiveclerk' ), false );

				return array(
					'steps'    => $steps,
					'finished' => true,
				);
			}

			$node = $graph->node( $nodeId );

			if ( null === $node ) {
				// Validation refuses this shape on save, so reaching here
				// means the graph changed under a run that was already
				// walking it. Stopping is the honest answer: there is no
				// node to resume from and inventing one would run steps
				// the operator deleted.
				$this->stop( $run, __( 'A step this run was waiting on has been removed.', 'hiveclerk' ), false );

				return array(
					'steps'    => $steps,
					'finished' => true,
				);
			}

			++$steps;

			$next = $this->enter( $run, $node, $context );

			if ( null === $next ) {
				// The node parked the run: a delay, a retry, or a failure.
				// Everything about its state is already written.
				return array(
					'steps'    => $steps,
					'finished' => ! $run->status->isOpen(),
				);
			}

			$nodeId  = $next->successor;
			$context = $next->context;

			$run->moveTo( $nodeId, $this->clock->now() );

			if ( NodeType::Action === $node->type ) {
				// Written before the next node is entered. Without this, a
				// process killed mid-pass would resume at the last node it
				// had saved and run the action again — and the action most
				// likely to be repeated is the one that sends email.
				$this->runs->save( $run );
			}

			if ( null === $nodeId ) {
				$this->finish( $run );

				return array(
					'steps'    => $steps,
					'finished' => true,
				);
			}
		}

		// Out of steps for this pass, not out of road. Saved as pending so
		// the next tick picks it straight back up.
		$run->currentNode = $nodeId;
		$this->runs->save( $run );

		return array(
			'steps'    => $steps,
			'finished' => false,
		);
	}

	/**
	 * Execute one node.
	 *
	 * @param WorkflowRun     $run     Run.
	 * @param WorkflowNode    $node    Node.
	 * @param WorkflowContext $context What the run knows.
	 * @return NodeTransition|null Where to go next, or null when the run parked.
	 */
	private function enter( WorkflowRun $run, WorkflowNode $node, WorkflowContext $context ): ?NodeTransition {
		return match ( $node->type ) {
			NodeType::Trigger   => new NodeTransition( $node->successor(), $context ),
			NodeType::Condition => $this->enterCondition( $run, $node, $context ),
			NodeType::Delay     => $this->enterDelay( $run, $node, $context ),
			NodeType::Action    => $this->enterAction( $run, $node, $context ),
		};
	}

	/**
	 * Evaluate a condition and take a branch.
	 *
	 * @param WorkflowRun     $run     Run.
	 * @param WorkflowNode    $node    Node.
	 * @param WorkflowContext $context What the run knows.
	 * @return NodeTransition
	 */
	private function enterCondition( WorkflowRun $run, WorkflowNode $node, WorkflowContext $context ): NodeTransition {
		$verdict = $this->conditions->evaluate( $node, $context );

		$this->record(
			$run,
			$node,
			$verdict['matched'] ? NodeOutcome::Matched : NodeOutcome::Unmatched,
			$verdict['detail']
		);

		return new NodeTransition( $node->successor( $verdict['matched'] ), $context );
	}

	/**
	 * Park the run until a delay has passed.
	 *
	 * The wait is measured from now rather than from when the run started,
	 * so "wait two days" after a condition means two days after the
	 * condition — which is what the screen says and what an operator
	 * counting on their fingers expects.
	 *
	 * @param WorkflowRun     $run     Run.
	 * @param WorkflowNode    $node    Node.
	 * @param WorkflowContext $context What the run knows.
	 * @return NodeTransition|null
	 */
	private function enterDelay( WorkflowRun $run, WorkflowNode $node, WorkflowContext $context ): ?NodeTransition {
		$minutes = $node->delayMinutes();

		if ( 0 === $minutes ) {
			// Refused by validation, so this is a hand-edited row. Treated
			// as no wait at all rather than as an error: the graph still
			// makes sense without it.
			return new NodeTransition( $node->successor(), $context );
		}

		$resumeAt = $this->clock->now()->modify( '+' . $minutes . ' minutes' );
		$next     = $node->successor();

		if ( null === $next ) {
			// A wait with nothing after it. Finishing now rather than in two
			// days is the same outcome and one less row in the table.
			$this->record( $run, $node, NodeOutcome::Finished, __( 'Nothing follows this wait.', 'hiveclerk' ) );
			$this->finish( $run );

			return null;
		}

		$this->record(
			$run,
			$node,
			NodeOutcome::Waited,
			sprintf(
				/* translators: %s: date and time the run resumes, UTC. */
				__( 'Waiting until %s UTC.', 'hiveclerk' ),
				$resumeAt->format( 'Y-m-d H:i' )
			)
		);

		$run->waitUntil( $next, $resumeAt );
		$this->runs->save( $run );

		return null;
	}

	/**
	 * Run an action.
	 *
	 * @param WorkflowRun     $run     Run.
	 * @param WorkflowNode    $node    Node.
	 * @param WorkflowContext $context What the run knows.
	 * @return NodeTransition|null
	 */
	private function enterAction( WorkflowRun $run, WorkflowNode $node, WorkflowContext $context ): ?NodeTransition {
		$type = $node->action();

		if ( null === $type ) {
			$this->failNode( $run, $node, __( 'This step names an action that does not exist.', 'hiveclerk' ) );

			return null;
		}

		$handler = $this->actions->get( $type );

		if ( null === $handler ) {
			// The module that performs this action is not installed. A skip
			// rather than a failure, so removing the email module stops the
			// emails without turning every workflow on the site red.
			$this->record(
				$run,
				$node,
				NodeOutcome::Skipped,
				sprintf(
					/* translators: %s: action name. */
					__( '%s is not available on this site.', 'hiveclerk' ),
					$type->label()
				)
			);

			return new NodeTransition( $node->successor(), $context );
		}

		$result = $this->execute( $handler, $context, $node->config );

		$this->record( $run, $node, $result->outcome, $result->detail );

		if ( $result->continues() ) {
			return new NodeTransition( $node->successor(), $context->with( $result->context ) );
		}

		if ( $result->isRetry() && ! $run->isExhausted() ) {
			$run->retryAt( $this->clock->now()->modify( '+' . self::RETRY_MINUTES . ' minutes' ) );
			$this->runs->save( $run );

			return null;
		}

		$this->failNode( $run, $node, $result->detail ?? __( 'The step could not be completed.', 'hiveclerk' ) );

		return null;
	}

	/**
	 * Call a handler with the re-entry guard raised.
	 *
	 * @param ActionHandlerInterface $handler Handler.
	 * @param WorkflowContext        $context What the run knows.
	 * @param array<string, mixed>   $config  Node configuration.
	 * @return ActionResult
	 */
	private function execute(
		ActionHandlerInterface $handler,
		WorkflowContext $context,
		array $config
	): ActionResult {
		$this->executing = true;

		try {
			return $handler->execute( $context, $config );
		} catch ( \Throwable $e ) {
			// Handlers are contracted not to throw and this is the net
			// under that contract. One run fails; the other twenty-four in
			// the batch are untouched.
			return ActionResult::failed( $e->getMessage() );
		} finally {
			$this->executing = false;
		}
	}

	/**
	 * Stop a run on a node that could not be completed.
	 *
	 * @param WorkflowRun  $run    Run.
	 * @param WorkflowNode $node   Node.
	 * @param string       $reason Why.
	 * @return void
	 */
	private function failNode( WorkflowRun $run, WorkflowNode $node, string $reason ): void {
		unset( $node );

		$run->fail( $reason, $this->clock->now() );
		$this->runs->save( $run );
	}

	/**
	 * Finish a run that reached the end of its graph.
	 *
	 * @param WorkflowRun $run Run.
	 * @return void
	 */
	private function finish( WorkflowRun $run ): void {
		$run->complete( $this->clock->now() );
		$this->runs->save( $run );

		$this->append(
			$run,
			WorkflowGraph::ENTRY,
			NodeType::Trigger,
			NodeOutcome::Finished,
			__( 'Reached the end of the workflow.', 'hiveclerk' )
		);
	}

	/**
	 * Stop a run for a reason outside the graph.
	 *
	 * @param WorkflowRun $run       Run.
	 * @param string      $reason    Why.
	 * @param bool        $cancelled Whether this is a cancellation rather than a failure.
	 * @return void
	 */
	private function stop( WorkflowRun $run, string $reason, bool $cancelled ): void {
		if ( $cancelled ) {
			$run->cancel( $reason, $this->clock->now() );
		} else {
			$run->fail( $reason, $this->clock->now() );
		}

		$this->runs->save( $run );

		$this->append(
			$run,
			$run->currentNode ?? WorkflowGraph::ENTRY,
			NodeType::Trigger,
			$cancelled ? NodeOutcome::Skipped : NodeOutcome::Failed,
			$reason
		);
	}

	/**
	 * Write one line of the run log.
	 *
	 * @param WorkflowRun  $run     Run.
	 * @param WorkflowNode $node    Node.
	 * @param NodeOutcome  $outcome What happened.
	 * @param string|null  $detail  Why.
	 * @return void
	 */
	private function record( WorkflowRun $run, WorkflowNode $node, NodeOutcome $outcome, ?string $detail ): void {
		$this->append( $run, $node->id, $node->type, $outcome, $detail );
	}

	/**
	 * Append to the run log, when there is a run to append to.
	 *
	 * @param WorkflowRun $run     Run.
	 * @param string      $nodeId  Node id.
	 * @param NodeType    $type    Node type.
	 * @param NodeOutcome $outcome What happened.
	 * @param string|null $detail  Why.
	 * @return void
	 */
	private function append(
		WorkflowRun $run,
		string $nodeId,
		NodeType $type,
		NodeOutcome $outcome,
		?string $detail
	): void {
		if ( null === $run->id ) {
			return;
		}

		$this->log->append(
			new RunLogEntry(
				id: null,
				runId: $run->id,
				nodeId: $nodeId,
				nodeType: $type,
				outcome: $outcome,
				detail: $detail,
				createdAt: $this->clock->now(),
			)
		);
	}
}
