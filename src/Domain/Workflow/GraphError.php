<?php
/**
 * A structural problem with a graph.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * A machine code, the node it concerns, and nothing translated.
 *
 * The domain layer cannot call `__()` — it is the one layer with no
 * WordPress in it at all — so validation reports codes and the module
 * turns them into sentences. That split is worth having anyway: the same
 * code drives the 422 body, the builder's inline warning and the test
 * suite's assertion, and none of the three should be matching English.
 */
final readonly class GraphError {

	public const MISSING_TRIGGER   = 'missing_trigger';
	public const EXTRA_TRIGGER     = 'extra_trigger';
	public const UNKNOWN_NODE      = 'unknown_node';
	public const DANGLING_EDGE     = 'dangling_edge';
	public const CYCLE             = 'cycle';
	public const UNREACHABLE       = 'unreachable';
	public const TOO_MANY_NODES    = 'too_many_nodes';
	public const EMPTY_GRAPH       = 'empty_graph';
	public const NO_ACTION         = 'no_action';
	public const CONDITION_FIELD   = 'condition_field';
	public const CONDITION_OP      = 'condition_operator';
	public const CONDITION_VALUE   = 'condition_value';
	public const CONDITION_NO_EDGE = 'condition_no_edge';
	public const ACTION_UNKNOWN    = 'action_unknown';
	public const ACTION_SUBJECT    = 'action_subject';
	public const ACTION_CONFIG     = 'action_config';
	public const DELAY_ZERO        = 'delay_zero';

	/**
	 * Construct.
	 *
	 * @param string               $code    One of this class's constants.
	 * @param string|null          $nodeId  Which node, when it is about one.
	 * @param array<string, mixed> $context Detail for the message.
	 */
	public function __construct(
		public string $code,
		public ?string $nodeId = null,
		public array $context = array(),
	) {
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'code'    => $this->code,
			'node'    => $this->nodeId,
			'context' => $this->context,
		);
	}
}
