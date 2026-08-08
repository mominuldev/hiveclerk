<?php
/**
 * Action lookup.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Services;

use Hiveclerk\Domain\Workflow\ActionHandlerInterface;
use Hiveclerk\Domain\Workflow\ActionType;

/**
 * The actions this install actually has.
 *
 * Registration is conditional on the module that implements the action
 * being present: a site that filtered out the email module has no
 * `enrol_sequence` handler, and the difference between "not registered"
 * and "registered but broken" is what lets the builder grey the node out
 * with a reason instead of the run failing at three in the morning.
 *
 * Third parties add their own through `hiveclerk/workflows/actions`. An
 * action registered under a type this product does not know is refused
 * rather than stored, because {@see ActionType} is what the graph
 * validator checks against and a handler outside it could never be
 * reached anyway.
 */
final class ActionRegistry {

	/**
	 * Handlers keyed by action value.
	 *
	 * @var array<string, ActionHandlerInterface>
	 */
	private array $handlers = array();

	/**
	 * Register a handler, replacing any previous one for its type.
	 *
	 * @param ActionHandlerInterface $handler Handler.
	 * @return void
	 */
	public function add( ActionHandlerInterface $handler ): void {
		$this->handlers[ $handler->type()->value ] = $handler;
	}

	/**
	 * Whether this install can perform an action.
	 *
	 * @param ActionType $type Action.
	 * @return bool
	 */
	public function has( ActionType $type ): bool {
		return isset( $this->handlers[ $type->value ] );
	}

	/**
	 * The handler for an action.
	 *
	 * @param ActionType $type Action.
	 * @return ActionHandlerInterface|null
	 */
	public function get( ActionType $type ): ?ActionHandlerInterface {
		return $this->handlers[ $type->value ] ?? null;
	}

	/**
	 * Every available action.
	 *
	 * @return array<int, ActionType>
	 */
	public function available(): array {
		$types = array();

		foreach ( array_keys( $this->handlers ) as $value ) {
			$type = ActionType::tryFromStorage( $value );

			if ( null !== $type ) {
				$types[] = $type;
			}
		}

		return $types;
	}
}
