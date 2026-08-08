<?php
/**
 * Where a node hands control next.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Services;

use Hiveclerk\Domain\Workflow\WorkflowContext;

/**
 * The successor and the context to carry into it.
 *
 * A two-field object rather than a tuple because the engine returns null
 * to mean "the run parked here", and a null inside an array would have to
 * be told apart from a null successor — which means the opposite.
 */
final readonly class NodeTransition {

	/**
	 * Construct.
	 *
	 * @param string|null     $successor Next node, or null to finish.
	 * @param WorkflowContext $context   Context for the next node.
	 */
	public function __construct(
		public ?string $successor,
		public WorkflowContext $context,
	) {
	}
}
