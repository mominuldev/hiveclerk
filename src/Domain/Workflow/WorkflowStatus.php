<?php
/**
 * Workflow lifecycle state.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * Draft, active, paused or archived.
 *
 * Two questions are asked of this enum and they are not the same one.
 * {@see self::starts()} decides whether a trigger may open a new run;
 * {@see self::advances()} decides whether the runs already open keep
 * moving. Pausing answers no to the first and yes to nothing else — a
 * paused workflow stops taking new subjects *and* holds the ones it has
 * exactly where they stand, so resuming continues rather than replaying.
 */
enum WorkflowStatus: string {

	case Draft    = 'draft';
	case Active   = 'active';
	case Paused   = 'paused';
	case Archived = 'archived';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Draft    => 'Draft',
			self::Active   => 'Active',
			self::Paused   => 'Paused',
			self::Archived => 'Archived',
		};
	}

	/**
	 * Whether a trigger may open a new run.
	 *
	 * @return bool
	 */
	public function starts(): bool {
		return self::Active === $this;
	}

	/**
	 * Whether runs already open may take another step.
	 *
	 * @return bool
	 */
	public function advances(): bool {
		return self::Active === $this;
	}

	/**
	 * Read a stored value, defaulting to the safest state.
	 *
	 * A row whose status column was written by a newer version, or
	 * corrupted, reads as a draft rather than as active. The failure of
	 * guessing wrong in the other direction is a workflow nobody
	 * configured sending email to the customer's list.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		if ( null === $value ) {
			return self::Draft;
		}

		return self::tryFrom( $value ) ?? self::Draft;
	}
}
