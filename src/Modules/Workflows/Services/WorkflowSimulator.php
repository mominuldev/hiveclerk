<?php
/**
 * Dry runs.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Services;

use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\NodeOutcome;
use Hiveclerk\Domain\Workflow\NodeType;
use Hiveclerk\Domain\Workflow\Workflow;
use Hiveclerk\Domain\Workflow\WorkflowContext;

/**
 * Walks a graph against a real lead and does absolutely nothing (FR-WFL-06).
 *
 * ## Real conditions, described actions
 *
 * Conditions are evaluated for real, because the question an operator is
 * asking is "would this lead take the yes branch", and a simulated
 * condition would only tell them what they already typed. Actions are
 * described rather than performed, because the same question does not
 * extend to "would you mind emailing them to find out".
 *
 * That split is what makes the dry run trustworthy enough to be the thing
 * an operator does before activating — and the exit criterion for this
 * feature is somebody building a workflow without reading documentation,
 * which they can only do if they can see what it would have done.
 *
 * ## Delays are announced, not waited
 *
 * The trace says "would wait 2 days" and carries straight on. Simulating
 * the wait would mean the trace stops at the first delay and shows
 * nothing of the branch that matters two days later, which is exactly the
 * part nobody can reason about in their head.
 */
final class WorkflowSimulator {

	/**
	 * Nodes a trace may include.
	 *
	 * The graph validator refuses cycles, so this only bounds a
	 * hand-edited row — and a trace of forty entries is already longer
	 * than anybody reads.
	 */
	public const MAX_STEPS = 40;

	/**
	 * Construct.
	 *
	 * @param ContextBuilder     $contexts   Context building.
	 * @param ConditionEvaluator $conditions Condition evaluation.
	 * @param ActionRegistry     $actions    Available actions.
	 */
	public function __construct(
		private readonly ContextBuilder $contexts,
		private readonly ConditionEvaluator $conditions,
		private readonly ActionRegistry $actions
	) {
	}

	/**
	 * Trace what a workflow would do to one lead.
	 *
	 * @param Workflow  $workflow Workflow.
	 * @param Lead|null $lead     Lead, or null for a shape-only walk.
	 * @return array<int, array{node: string, type: string, outcome: string, detail: string}>
	 */
	public function simulate( Workflow $workflow, ?Lead $lead ): array {
		$context = null === $lead
			? new WorkflowContext( array( 'workflow.name' => $workflow->name ) )
			: $this->contexts->forLead( $lead )->with( array( 'workflow.name' => $workflow->name ) );

		$graph = $workflow->graph;
		$trace = array();
		$id    = $graph->entry();

		if ( null === $id ) {
			return array(
				array(
					'node'    => 'trigger',
					'type'    => NodeType::Trigger->value,
					'outcome' => NodeOutcome::Finished->value,
					'detail'  => __( 'Nothing follows the trigger yet.', 'hiveclerk' ),
				),
			);
		}

		while ( null !== $id && count( $trace ) < self::MAX_STEPS ) {
			$node = $graph->node( $id );

			if ( null === $node ) {
				$trace[] = array(
					'node'    => $id,
					'type'    => NodeType::Action->value,
					'outcome' => NodeOutcome::Failed->value,
					'detail'  => __( 'This step no longer exists.', 'hiveclerk' ),
				);

				break;
			}

			switch ( $node->type ) {
				case NodeType::Condition:
					$verdict = $this->conditions->evaluate( $node, $context );

					$trace[] = array(
						'node'    => $id,
						'type'    => $node->type->value,
						'outcome' => $verdict['matched'] ? NodeOutcome::Matched->value : NodeOutcome::Unmatched->value,
						'detail'  => $verdict['detail'],
					);

					$id = $node->successor( $verdict['matched'] );
					break;

				case NodeType::Delay:
					$trace[] = array(
						'node'    => $id,
						'type'    => $node->type->value,
						'outcome' => NodeOutcome::Waited->value,
						'detail'  => $this->describeDelay( $node->delayMinutes() ),
					);

					$id = $node->successor();
					break;

				case NodeType::Action:
					$trace[] = array(
						'node'    => $id,
						'type'    => $node->type->value,
						'outcome' => NodeOutcome::Skipped->value,
						'detail'  => $this->describeAction( $node->config, $context, $node->action() ),
					);

					$id = $node->successor();
					break;

				case NodeType::Trigger:
					$id = $node->successor();
					break;
			}
		}

		$trace[] = array(
			'node'    => 'end',
			'type'    => NodeType::Trigger->value,
			'outcome' => NodeOutcome::Finished->value,
			'detail'  => null === $lead
				? __( 'This is the shape of the workflow. Pick a lead to see which branches they would take.', 'hiveclerk' )
				: __( 'Nothing above was actually done — this was a dry run.', 'hiveclerk' ),
		);

		return $trace;
	}

	/**
	 * One line for a wait.
	 *
	 * @param int $minutes Minutes.
	 * @return string
	 */
	private function describeDelay( int $minutes ): string {
		if ( $minutes >= 1440 ) {
			$days = intdiv( $minutes, 1440 );

			return sprintf(
				/* translators: %d: number of days. */
				_n( 'Would wait %d day.', 'Would wait %d days.', $days, 'hiveclerk' ),
				$days
			);
		}

		if ( $minutes >= 60 ) {
			$hours = intdiv( $minutes, 60 );

			return sprintf(
				/* translators: %d: number of hours. */
				_n( 'Would wait %d hour.', 'Would wait %d hours.', $hours, 'hiveclerk' ),
				$hours
			);
		}

		return sprintf(
			/* translators: %d: number of minutes. */
			_n( 'Would wait %d minute.', 'Would wait %d minutes.', $minutes, 'hiveclerk' ),
			$minutes
		);
	}

	/**
	 * One line for an action, without performing it.
	 *
	 * @param array<string, mixed> $config  Node configuration.
	 * @param WorkflowContext      $context What the run would know.
	 * @param ActionType|null      $type    Action.
	 * @return string
	 */
	private function describeAction( array $config, WorkflowContext $context, ?ActionType $type ): string {
		if ( null === $type ) {
			return __( 'This step names an action that does not exist.', 'hiveclerk' );
		}

		$handler = $this->actions->get( $type );

		if ( null === $handler ) {
			return sprintf(
				/* translators: %s: action name. */
				__( '%s is not available on this site.', 'hiveclerk' ),
				$type->label()
			);
		}

		return $handler->describe( $context, $config );
	}
}
