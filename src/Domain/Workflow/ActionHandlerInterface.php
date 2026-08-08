<?php
/**
 * The port an action implements.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * One thing a workflow can do.
 *
 * The port lives in the domain and every implementation lives in the
 * module that owns the behaviour, which is what lets a site without the
 * email module keep a working engine: the handler is simply never
 * registered, and the graph validator says so on the node rather than the
 * run failing at three in the morning.
 */
interface ActionHandlerInterface {

	/**
	 * Which action this handles.
	 *
	 * @return ActionType
	 */
	public function type(): ActionType;

	/**
	 * Do the work.
	 *
	 * Implementations are called from a background job and must not throw:
	 * an exception here takes down the whole tick, and with it every other
	 * customer's run in the same batch. Return a failure instead.
	 *
	 * @param WorkflowContext      $context What the run knows.
	 * @param array<string, mixed> $config  Node configuration.
	 * @return ActionResult
	 */
	public function execute( WorkflowContext $context, array $config ): ActionResult;

	/**
	 * Whether this node's configuration is complete enough to activate.
	 *
	 * Checked on save and on activation, not during the run. Returns a
	 * reason when something is wrong — "the sequence this points at has
	 * been deleted" is the message that saves an operator an afternoon.
	 *
	 * @param array<string, mixed> $config Node configuration.
	 * @return string|null Reason it cannot run, or null when it can.
	 */
	public function validate( array $config ): ?string;

	/**
	 * What this node would do, in one line, without doing it.
	 *
	 * @param WorkflowContext      $context What the run knows.
	 * @param array<string, mixed> $config  Node configuration.
	 * @return string
	 */
	public function describe( WorkflowContext $context, array $config ): string;
}
