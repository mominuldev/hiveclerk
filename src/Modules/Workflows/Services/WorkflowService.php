<?php
/**
 * Workflow management.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Services;

use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Domain\Workflow\GraphError;
use Hiveclerk\Domain\Workflow\NodeType;
use Hiveclerk\Domain\Workflow\TriggerEvent;
use Hiveclerk\Domain\Workflow\Workflow;
use Hiveclerk\Domain\Workflow\WorkflowGraph;
use Hiveclerk\Domain\Workflow\WorkflowRepositoryInterface;
use Hiveclerk\Domain\Workflow\WorkflowRunRepositoryInterface;
use Hiveclerk\Domain\Workflow\WorkflowStatus;

/**
 * Creates, edits and activates workflows (FR-WFL-05).
 *
 * ## Activation is a gate, and it explains itself
 *
 * A workflow that cannot run is refused activation with the list of
 * reasons, node by node, in the words the operator needs — "the sequence
 * this step points at no longer exists", not "validation failed". The
 * builder puts those on the button. This is the same shape the sequence
 * builder settled on, because it is the one that stops an operator
 * finding out from a recipient.
 *
 * ## Editing a live workflow is allowed, and open runs keep the old graph
 *
 * Not quite: they keep the *node ids*. A run parked on a delay resumes
 * into whatever that node is now, and if the node was deleted the run
 * stops with a line saying so. The alternative — versioning the graph and
 * pinning every run to the version it started under — is the right answer
 * for a mature product and the wrong one for the first release: it makes
 * every edit a fork, and an operator fixing a typo would be watching two
 * versions run side by side with no screen that shows both.
 */
final class WorkflowService {

	/**
	 * Longest name a workflow may carry.
	 */
	public const MAX_NAME = 120;

	/**
	 * Construct.
	 *
	 * @param WorkflowRepositoryInterface    $workflows Workflow storage.
	 * @param WorkflowRunRepositoryInterface $runs      Run storage.
	 * @param ActionRegistry                 $actions   Available actions.
	 * @param AuditLogger                    $audit     Audit trail.
	 * @param ClockInterface                 $clock     Clock.
	 */
	public function __construct(
		private readonly WorkflowRepositoryInterface $workflows,
		private readonly WorkflowRunRepositoryInterface $runs,
		private readonly ActionRegistry $actions,
		private readonly AuditLogger $audit,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Create a draft workflow.
	 *
	 * @param string        $name    What to call it.
	 * @param TriggerEvent  $trigger What starts it.
	 * @param WorkflowGraph|null $graph Starting graph, for templates.
	 * @param array<string, mixed> $triggerConfig Trigger configuration.
	 * @return Workflow
	 */
	public function create(
		string $name,
		TriggerEvent $trigger = TriggerEvent::LeadCaptured,
		?WorkflowGraph $graph = null,
		array $triggerConfig = array()
	): Workflow {
		$workflow = $this->workflows->save(
			new Workflow(
				id: null,
				uuid: Uuid::generate(),
				name: $this->cleanName( $name ),
				status: WorkflowStatus::Draft,
				trigger: $trigger,
				triggerConfig: $triggerConfig,
				graph: $graph ?? WorkflowGraph::seed(),
				createdAt: $this->clock->now(),
			)
		);

		$this->audit->record(
			'workflow.created',
			array(
				'name'    => $workflow->name,
				'trigger' => $trigger->value,
			),
			'workflow',
			$workflow->id
		);

		return $workflow;
	}

	/**
	 * Apply changes.
	 *
	 * @param Workflow             $workflow Workflow.
	 * @param array<string, mixed> $changes  Cleaned input.
	 * @return Workflow
	 */
	public function update( Workflow $workflow, array $changes ): Workflow {
		$recorded = array();

		if ( isset( $changes['name'] ) && is_string( $changes['name'] ) ) {
			$workflow->name   = $this->cleanName( $changes['name'] );
			$recorded['name'] = $workflow->name;
		}

		if ( isset( $changes['trigger'] ) && is_string( $changes['trigger'] ) ) {
			$trigger = TriggerEvent::tryFrom( $changes['trigger'] );

			if ( null !== $trigger && $trigger !== $workflow->trigger ) {
				$workflow->trigger   = $trigger;
				$recorded['trigger'] = $trigger->value;

				// A trigger change resets the schedule clock. Switching to a
				// scheduled trigger with a next_run_at left over from a
				// previous configuration would sweep on the next tick rather
				// than after the interval.
				$workflow->nextRunAt = $trigger->isScheduled()
					? $this->clock->now()->modify( '+' . $workflow->intervalMinutes() . ' minutes' )
					: null;
			}
		}

		if ( isset( $changes['trigger_config'] ) && is_array( $changes['trigger_config'] ) ) {
			$workflow->triggerConfig    = $changes['trigger_config'];
			$recorded['trigger_config'] = true;
		}

		if ( isset( $changes['graph'] ) && $changes['graph'] instanceof WorkflowGraph ) {
			$workflow->graph   = $changes['graph'];
			$recorded['nodes'] = $workflow->graph->size();
		}

		if ( array_key_exists( 'runs_once', $changes ) ) {
			$workflow->runsOnce    = (bool) $changes['runs_once'];
			$recorded['runs_once'] = $workflow->runsOnce;
		}

		$workflow->updatedAt = $this->clock->now();

		$saved = $this->workflows->save( $workflow );

		if ( array() !== $recorded ) {
			$this->audit->record( 'workflow.updated', $recorded, 'workflow', $saved->id );
		}

		return $saved;
	}

	/**
	 * Activate, if nothing is blocking it.
	 *
	 * @param Workflow $workflow Workflow.
	 * @return array<int, array{node: string|null, message: string}> Blockers; empty on success.
	 */
	public function activate( Workflow $workflow ): array {
		$blockers = $this->blockers( $workflow );

		if ( array() !== $blockers ) {
			return $blockers;
		}

		$workflow->status = WorkflowStatus::Active;

		if ( $workflow->trigger->isScheduled() && null === $workflow->nextRunAt ) {
			// The first sweep runs one interval from now rather than
			// immediately. Activating a daily workflow and having it mail
			// the whole segment the same second is a surprise nobody wants
			// twice.
			$workflow->nextRunAt = $this->clock->now()
				->modify( '+' . $workflow->intervalMinutes() . ' minutes' );
		}

		$this->workflows->save( $workflow );

		$this->audit->record(
			'workflow.activated',
			array(
				'name'    => $workflow->name,
				'trigger' => $workflow->trigger->value,
			),
			'workflow',
			$workflow->id
		);

		return array();
	}

	/**
	 * Pause, leaving open runs exactly where they stand.
	 *
	 * @param Workflow $workflow Workflow.
	 * @return Workflow
	 */
	public function pause( Workflow $workflow ): Workflow {
		$workflow->status = WorkflowStatus::Paused;

		$saved = $this->workflows->save( $workflow );

		$this->audit->record( 'workflow.paused', array( 'name' => $workflow->name ), 'workflow', $saved->id );

		return $saved;
	}

	/**
	 * Delete, and stop everything it had running.
	 *
	 * @param Workflow $workflow Workflow.
	 * @return int Runs cancelled.
	 */
	public function delete( Workflow $workflow ): int {
		if ( null === $workflow->id ) {
			return 0;
		}

		$this->workflows->softDelete( $workflow->id );

		// Cancelled rather than left. An open run against a deleted
		// workflow would be picked up by the tick for ever, find nothing,
		// and be cancelled one at a time — a queue that never drains for a
		// workflow nobody can see any more.
		$cancelled = $this->runs->cancelOpen(
			$workflow->id,
			__( 'The workflow was deleted.', 'hiveclerk' )
		);

		$this->audit->record(
			'workflow.deleted',
			array(
				'name'           => $workflow->name,
				'runs_cancelled' => $cancelled,
			),
			'workflow',
			$workflow->id
		);

		return $cancelled;
	}

	/**
	 * Everything standing between this workflow and activation.
	 *
	 * @param Workflow $workflow Workflow.
	 * @return array<int, array{node: string|null, message: string}>
	 */
	public function blockers( Workflow $workflow ): array {
		$blockers = array();

		foreach ( $workflow->graph->errors( $workflow->subject() ) as $error ) {
			$blockers[] = array(
				'node'    => $error->nodeId,
				'message' => self::describe( $error ),
			);
		}

		foreach ( $workflow->graph->nodes as $id => $node ) {
			if ( NodeType::Action !== $node->type ) {
				continue;
			}

			$action = $node->action();

			if ( null === $action ) {
				continue;
			}

			$handler = $this->actions->get( $action );

			if ( null === $handler ) {
				$blockers[] = array(
					'node'    => $id,
					'message' => sprintf(
						/* translators: %s: action name. */
						__( '%s is not available on this site.', 'hiveclerk' ),
						$action->label()
					),
				);

				continue;
			}

			$reason = $handler->validate( $node->config );

			if ( null !== $reason ) {
				$blockers[] = array(
					'node'    => $id,
					'message' => $reason,
				);
			}
		}

		if ( $workflow->trigger->isScheduled() && array() === $workflow->segment() ) {
			$blockers[] = array(
				'node'    => WorkflowGraph::ENTRY,
				'message' => __( 'A scheduled workflow needs a filter, so it does not run against every lead you have.', 'hiveclerk' ),
			);
		}

		return $blockers;
	}

	/**
	 * A structural error, in words.
	 *
	 * @param GraphError $error Error.
	 * @return string
	 */
	public static function describe( GraphError $error ): string {
		return match ( $error->code ) {
			GraphError::EMPTY_GRAPH       => __( 'This workflow has no steps yet.', 'hiveclerk' ),
			GraphError::MISSING_TRIGGER   => __( 'This workflow has lost its trigger. Recreate it.', 'hiveclerk' ),
			GraphError::EXTRA_TRIGGER     => __( 'A workflow can only have one trigger.', 'hiveclerk' ),
			GraphError::DANGLING_EDGE     => __( 'This step leads to one that has been deleted.', 'hiveclerk' ),
			GraphError::CYCLE             => __( 'These steps lead back to each other, which would never stop.', 'hiveclerk' ),
			GraphError::UNREACHABLE       => __( 'Nothing leads to this step, so it would never run.', 'hiveclerk' ),
			GraphError::TOO_MANY_NODES    => sprintf(
				/* translators: %d: maximum number of steps. */
				__( 'A workflow can have at most %d steps.', 'hiveclerk' ),
				is_numeric( $error->context['max'] ?? null ) ? (int) $error->context['max'] : WorkflowGraph::MAX_NODES
			),
			GraphError::NO_ACTION         => __( 'This workflow decides things but never does anything. Add an action.', 'hiveclerk' ),
			GraphError::CONDITION_FIELD   => __( 'Choose what this condition should look at.', 'hiveclerk' ),
			GraphError::CONDITION_OP      => __( 'Choose how this condition compares.', 'hiveclerk' ),
			GraphError::CONDITION_VALUE   => __( 'Give this condition something to compare against.', 'hiveclerk' ),
			GraphError::CONDITION_NO_EDGE => __( 'This condition has nothing after either answer.', 'hiveclerk' ),
			GraphError::ACTION_UNKNOWN    => __( 'This step names an action that does not exist.', 'hiveclerk' ),
			GraphError::ACTION_SUBJECT    => __( 'This action needs a lead, and this trigger does not always have one.', 'hiveclerk' ),
			GraphError::DELAY_ZERO        => __( 'Set how long this step should wait.', 'hiveclerk' ),
			default                       => __( 'This step is not ready.', 'hiveclerk' ),
		};
	}

	/**
	 * A trimmed, bounded name.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private function cleanName( string $name ): string {
		$trimmed = trim( $name );

		if ( '' === $trimmed ) {
			return __( 'Untitled workflow', 'hiveclerk' );
		}

		return mb_substr( $trimmed, 0, self::MAX_NAME );
	}
}
