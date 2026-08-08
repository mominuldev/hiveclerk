<?php
/**
 * The node graph a workflow executes.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * Nodes, edges, and the rules that make a graph safe to run (FR-WFL-02).
 *
 * ## Validation happens before activation, never during a run
 *
 * The engine walks this graph inside a background job, one node at a
 * time, against a customer's live data. Discovering there that an edge
 * points at a deleted node means a half-executed run: the email already
 * sent, the stage already moved, and no sensible place to resume. So
 * every structural question — is there exactly one trigger, does every
 * edge land somewhere, can this graph loop — is answered once, on save
 * and again on activate, and the engine is written to assume the answers.
 *
 * ## Cycles are rejected rather than bounded
 *
 * A step limit would also stop an infinite loop, and it is the wrong
 * tool: it stops the loop after it has sent forty emails. A graph that
 * can reach the same node twice is refused at the door, which is a rule
 * an operator can understand from the message and fix in one edit.
 * {@see WorkflowRun::$steps} still exists as a backstop, because a rule
 * enforced in one place is a rule until somebody writes a second door.
 */
final readonly class WorkflowGraph {

	/**
	 * The id every graph's trigger node carries.
	 */
	public const ENTRY = 'trigger';

	/**
	 * Most nodes one workflow may hold.
	 *
	 * Not a technical ceiling — the engine would happily walk a thousand.
	 * It is the point past which nobody can read the thing they built, and
	 * an unreadable automation touching customer data is a liability
	 * whatever it does.
	 */
	public const MAX_NODES = 40;

	/**
	 * Construct.
	 *
	 * @param array<string, WorkflowNode> $nodes Nodes keyed by id.
	 */
	public function __construct( public array $nodes = array() ) {
	}

	/**
	 * An empty graph holding only its trigger.
	 *
	 * @return self
	 */
	public static function seed(): self {
		return new self(
			array(
				self::ENTRY => new WorkflowNode( self::ENTRY, NodeType::Trigger ),
			)
		);
	}

	/**
	 * Rebuild from a stored JSON column.
	 *
	 * Unknown node types are dropped rather than fataling. A row written
	 * by a newer version of the plugin — a site rolled back after an
	 * upgrade — then loses the step it cannot understand, and validation
	 * reports the dangling edge that leaves. The alternative is an admin
	 * screen that white-screens on one bad row.
	 *
	 * @param array<mixed> $stored Decoded JSON.
	 * @return self
	 */
	public static function fromArray( array $stored ): self {
		$nodes = array();

		foreach ( $stored as $id => $row ) {
			if ( ! is_string( $id ) || '' === $id || ! is_array( $row ) ) {
				continue;
			}

			$node = WorkflowNode::fromArray( $id, $row );

			if ( null !== $node ) {
				$nodes[ $id ] = $node;
			}
		}

		return new self( $nodes );
	}

	/**
	 * Storage form.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function toArray(): array {
		$stored = array();

		foreach ( $this->nodes as $id => $node ) {
			$stored[ $id ] = $node->toArray();
		}

		return $stored;
	}

	/**
	 * One node.
	 *
	 * @param string|null $id Node id.
	 * @return WorkflowNode|null
	 */
	public function node( ?string $id ): ?WorkflowNode {
		if ( null === $id || '' === $id ) {
			return null;
		}

		return $this->nodes[ $id ] ?? null;
	}

	/**
	 * The trigger node.
	 *
	 * @return WorkflowNode|null
	 */
	public function trigger(): ?WorkflowNode {
		return $this->node( self::ENTRY );
	}

	/**
	 * The first node after the trigger.
	 *
	 * @return string|null
	 */
	public function entry(): ?string {
		return $this->trigger()?->successor();
	}

	/**
	 * How many nodes there are, trigger included.
	 *
	 * @return int
	 */
	public function size(): int {
		return count( $this->nodes );
	}

	/**
	 * Every action this graph can perform.
	 *
	 * Used by the builder to warn before activation and by the licence
	 * gate, which cares whether a workflow reaches outside the site.
	 *
	 * @return array<int, ActionType>
	 */
	public function actions(): array {
		$actions = array();

		foreach ( $this->nodes as $node ) {
			$action = $node->action();

			if ( null !== $action ) {
				$actions[ $action->value ] = $action;
			}
		}

		return array_values( $actions );
	}

	/**
	 * Node ids reachable from the trigger.
	 *
	 * @return array<int, string>
	 */
	public function reachable(): array {
		$seen  = array();
		$queue = array( self::ENTRY );

		while ( array() !== $queue ) {
			$id = array_shift( $queue );

			if ( isset( $seen[ $id ] ) || ! isset( $this->nodes[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;

			foreach ( $this->nodes[ $id ]->edges() as $edge ) {
				$queue[] = $edge;
			}
		}

		return array_keys( $seen );
	}

	/**
	 * Every structural problem, or an empty array when there are none.
	 *
	 * Action configuration is checked by the module that owns the action,
	 * not here — the domain layer has no way to know whether sequence 14
	 * exists. What is checked here is everything about shape, which is
	 * the part that makes the engine's assumptions safe.
	 *
	 * @param SubjectType $subject What the run will be about.
	 * @return array<int, GraphError>
	 */
	public function errors( SubjectType $subject = SubjectType::Lead ): array {
		$errors = array();

		if ( array() === $this->nodes ) {
			return array( new GraphError( GraphError::EMPTY_GRAPH ) );
		}

		if ( null === $this->trigger() ) {
			$errors[] = new GraphError( GraphError::MISSING_TRIGGER );
		}

		if ( $this->size() > self::MAX_NODES ) {
			$errors[] = new GraphError(
				GraphError::TOO_MANY_NODES,
				null,
				array(
					'max'   => self::MAX_NODES,
					'count' => $this->size(),
				)
			);
		}

		foreach ( $this->nodes as $id => $node ) {
			if ( self::ENTRY !== $id && NodeType::Trigger === $node->type ) {
				$errors[] = new GraphError( GraphError::EXTRA_TRIGGER, $id );
			}

			foreach ( $node->edges() as $edge ) {
				if ( ! isset( $this->nodes[ $edge ] ) ) {
					$errors[] = new GraphError(
						GraphError::DANGLING_EDGE,
						$id,
						array( 'target' => $edge )
					);
				}
			}

			foreach ( $this->nodeErrors( $id, $node, $subject ) as $error ) {
				$errors[] = $error;
			}
		}

		foreach ( $this->orphans() as $orphan ) {
			$errors[] = new GraphError( GraphError::UNREACHABLE, $orphan );
		}

		$cycle = $this->firstCycle();

		if ( null !== $cycle ) {
			$errors[] = new GraphError( GraphError::CYCLE, $cycle );
		}

		if ( array() === $this->actions() ) {
			// A workflow with no action is a workflow that spends the tick
			// budget deciding to do nothing. It is almost always a graph
			// somebody stopped building halfway.
			$errors[] = new GraphError( GraphError::NO_ACTION );
		}

		return $errors;
	}

	/**
	 * Whether this graph is safe to activate.
	 *
	 * @param SubjectType $subject What the run will be about.
	 * @return bool
	 */
	public function isValid( SubjectType $subject = SubjectType::Lead ): bool {
		return array() === $this->errors( $subject );
	}

	/**
	 * Nodes that exist but nothing leads to.
	 *
	 * @return array<int, string>
	 */
	private function orphans(): array {
		$reachable = array_flip( $this->reachable() );
		$orphans   = array();

		foreach ( array_keys( $this->nodes ) as $id ) {
			if ( ! isset( $reachable[ $id ] ) ) {
				$orphans[] = $id;
			}
		}

		return $orphans;
	}

	/**
	 * The first node that can be reached twice, if any.
	 *
	 * A depth-first walk carrying its own path, rather than a colour map
	 * over the whole graph. Both find a cycle; this one can name the node
	 * the operator has to look at, and a message that says "Wait 2 days
	 * leads back to itself" is a message somebody acts on.
	 *
	 * @return string|null
	 */
	private function firstCycle(): ?string {
		$visited = array();

		return $this->walkForCycle( self::ENTRY, array(), $visited );
	}

	/**
	 * Depth-first search for a node already on the current path.
	 *
	 * @param string              $id      Node to walk from.
	 * @param array<string, true> $path    Nodes on the path to here.
	 * @param array<string, true> $visited Nodes already cleared, by reference.
	 * @return string|null The repeated node, or null.
	 */
	private function walkForCycle( string $id, array $path, array &$visited ): ?string {
		if ( isset( $path[ $id ] ) ) {
			return $id;
		}

		if ( isset( $visited[ $id ] ) || ! isset( $this->nodes[ $id ] ) ) {
			return null;
		}

		$visited[ $id ] = true;
		$path[ $id ]    = true;

		foreach ( $this->nodes[ $id ]->edges() as $edge ) {
			$found = $this->walkForCycle( $edge, $path, $visited );

			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Problems with one node's own configuration.
	 *
	 * @param string       $id      Node id.
	 * @param WorkflowNode $node    Node.
	 * @param SubjectType  $subject What the run will be about.
	 * @return array<int, GraphError>
	 */
	private function nodeErrors( string $id, WorkflowNode $node, SubjectType $subject ): array {
		return match ( $node->type ) {
			NodeType::Condition => $this->conditionErrors( $id, $node ),
			NodeType::Action    => $this->actionErrors( $id, $node, $subject ),
			NodeType::Delay     => 0 === $node->delayMinutes()
				? array( new GraphError( GraphError::DELAY_ZERO, $id ) )
				: array(),
			NodeType::Trigger   => array(),
		};
	}

	/**
	 * Problems with a condition node.
	 *
	 * @param string       $id   Node id.
	 * @param WorkflowNode $node Node.
	 * @return array<int, GraphError>
	 */
	private function conditionErrors( string $id, WorkflowNode $node ): array {
		$errors = array();
		$field  = ConditionField::tryFromStorage( $node->string( 'field' ) );

		if ( null === $field ) {
			$errors[] = new GraphError( GraphError::CONDITION_FIELD, $id );
		}

		$operator = ConditionOperator::tryFromStorage( $node->string( 'operator' ) );

		if ( null === $operator ) {
			$errors[] = new GraphError( GraphError::CONDITION_OP, $id );

			return $errors;
		}

		if ( $operator->needsValue() && null === $node->string( 'value' ) ) {
			$errors[] = new GraphError( GraphError::CONDITION_VALUE, $id );
		}

		if ( null !== $field && $field->needsKey() && null === $node->string( 'key' ) ) {
			$errors[] = new GraphError( GraphError::CONDITION_VALUE, $id );
		}

		if ( null === $node->yes && null === $node->no ) {
			// Both branches empty ends the run either way, so the condition
			// is doing nothing but costing a query.
			$errors[] = new GraphError( GraphError::CONDITION_NO_EDGE, $id );
		}

		return $errors;
	}

	/**
	 * Problems with an action node.
	 *
	 * @param string       $id      Node id.
	 * @param WorkflowNode $node    Node.
	 * @param SubjectType  $subject What the run will be about.
	 * @return array<int, GraphError>
	 */
	private function actionErrors( string $id, WorkflowNode $node, SubjectType $subject ): array {
		$action = $node->action();

		if ( null === $action ) {
			return array( new GraphError( GraphError::ACTION_UNKNOWN, $id ) );
		}

		if ( ! $action->supports( $subject ) ) {
			return array(
				new GraphError(
					GraphError::ACTION_SUBJECT,
					$id,
					array(
						'action'  => $action->value,
						'subject' => $subject->value,
					)
				),
			);
		}

		return array();
	}
}
