<?php
/**
 * Sequence steps without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Email;

use Hiveclerk\Domain\Email\SequenceStep;
use Hiveclerk\Domain\Email\SequenceStepRepositoryInterface;

/**
 * Steps, in memory.
 *
 * @internal
 */
final class InMemorySteps implements SequenceStepRepositoryInterface {

	/**
	 * Stored steps by id.
	 *
	 * @var array<int, SequenceStep>
	 */
	public array $rows = array();

	private int $nextId = 1;

	public function find( int $id ): ?SequenceStep {
		return $this->rows[ $id ] ?? null;
	}

	public function forSequence( int $sequenceId ): array {
		$steps = array_values(
			array_filter(
				$this->rows,
				static fn ( SequenceStep $step ): bool => $step->sequenceId === $sequenceId
			)
		);

		usort(
			$steps,
			static fn ( SequenceStep $a, SequenceStep $b ): int => $a->position <=> $b->position
		);

		return $steps;
	}

	public function atPosition( int $sequenceId, int $position ): ?SequenceStep {
		foreach ( $this->forSequence( $sequenceId ) as $step ) {
			if ( $step->position === $position ) {
				return $step;
			}
		}

		return null;
	}

	public function save( SequenceStep $step ): SequenceStep {
		if ( null === $step->id ) {
			$step->id = $this->nextId++;
		}

		$this->rows[ $step->id ] = $step;

		return $step;
	}

	public function delete( int $id ): bool {
		unset( $this->rows[ $id ] );

		return true;
	}

	public function reorder( array $ids ): void {
		$position = 0;

		foreach ( $ids as $id ) {
			if ( isset( $this->rows[ $id ] ) ) {
				$this->rows[ $id ]->position = $position;
			}

			++$position;
		}
	}

	public function countFor( int $sequenceId ): int {
		return count( $this->forSequence( $sequenceId ) );
	}
}
