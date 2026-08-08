<?php
/**
 * Kinds of node in a workflow graph.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * Trigger, condition, delay, action.
 *
 * There is no "stop" node. A node whose outgoing edge is null ends the
 * run, which means an operator deleting the last step cannot leave a
 * dangling reference to a terminator that no longer exists — the most
 * common way a hand-built graph breaks.
 */
enum NodeType: string {

	case Trigger   = 'trigger';
	case Condition = 'condition';
	case Delay     = 'delay';
	case Action    = 'action';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Trigger   => 'Trigger',
			self::Condition => 'Condition',
			self::Delay     => 'Wait',
			self::Action    => 'Action',
		};
	}

	/**
	 * Whether this node branches rather than continuing straight on.
	 *
	 * @return bool
	 */
	public function branches(): bool {
		return self::Condition === $this;
	}

	/**
	 * Read a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self|null Null when the value names no known type.
	 */
	public static function tryFromStorage( ?string $value ): ?self {
		return null === $value ? null : self::tryFrom( $value );
	}
}
