<?php
/**
 * Run lifecycle state.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * Where a single run has got to.
 *
 * `Waiting` and `Pending` are distinguished on purpose. Pending is a run
 * that has never taken a step; waiting is one sitting on a delay node
 * with a resume time. They advance through the same query, but only the
 * second is a state an operator should read as "working" — a queue full
 * of pending runs means the tick is not running, and a queue full of
 * waiting ones means the workflow is doing exactly what it was told.
 */
enum RunStatus: string {

	case Pending   = 'pending';
	case Waiting   = 'waiting';
	case Completed = 'completed';
	case Failed    = 'failed';
	case Cancelled = 'cancelled';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Pending   => 'Starting',
			self::Waiting   => 'Waiting',
			self::Completed => 'Finished',
			self::Failed    => 'Failed',
			self::Cancelled => 'Cancelled',
		};
	}

	/**
	 * Whether the run still has somewhere to go.
	 *
	 * @return bool
	 */
	public function isOpen(): bool {
		return match ( $this ) {
			self::Pending, self::Waiting => true,
			default                      => false,
		};
	}

	/**
	 * Read a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		if ( null === $value ) {
			return self::Pending;
		}

		return self::tryFrom( $value ) ?? self::Pending;
	}
}
