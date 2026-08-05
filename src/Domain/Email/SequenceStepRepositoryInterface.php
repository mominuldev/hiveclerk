<?php
/**
 * Step storage contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

/**
 * Where sequence steps live.
 */
interface SequenceStepRepositoryInterface {

	/**
	 * One step by id.
	 *
	 * @param int $id Storage id.
	 * @return SequenceStep|null
	 */
	public function find( int $id ): ?SequenceStep;

	/**
	 * Every step of a sequence, in order.
	 *
	 * @param int $sequenceId Sequence.
	 * @return array<int, SequenceStep>
	 */
	public function forSequence( int $sequenceId ): array;

	/**
	 * The step at one position, or null past the end.
	 *
	 * @param int $sequenceId Sequence.
	 * @param int $position   Zero-based position.
	 * @return SequenceStep|null
	 */
	public function atPosition( int $sequenceId, int $position ): ?SequenceStep;

	/**
	 * Insert or update.
	 *
	 * @param SequenceStep $step Step.
	 * @return SequenceStep
	 */
	public function save( SequenceStep $step ): SequenceStep;

	/**
	 * Remove a step.
	 *
	 * @param int $id Storage id.
	 * @return bool
	 */
	public function delete( int $id ): bool;

	/**
	 * Write a new order.
	 *
	 * @param array<int, int> $ids Step ids in their new order.
	 * @return void
	 */
	public function reorder( array $ids ): void;

	/**
	 * How many steps a sequence has.
	 *
	 * @param int $sequenceId Sequence.
	 * @return int
	 */
	public function countFor( int $sequenceId ): int;
}
