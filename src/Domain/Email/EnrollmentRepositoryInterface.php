<?php
/**
 * Enrolment storage contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

/**
 * Where enrolments live.
 */
interface EnrollmentRepositoryInterface {

	/**
	 * One enrolment by id.
	 *
	 * @param int $id Storage id.
	 * @return Enrollment|null
	 */
	public function find( int $id ): ?Enrollment;

	/**
	 * One lead's enrolment in one sequence.
	 *
	 * @param int $sequenceId Sequence.
	 * @param int $leadId     Lead.
	 * @return Enrollment|null
	 */
	public function findFor( int $sequenceId, int $leadId ): ?Enrollment;

	/**
	 * Enrolments whose next step is due, oldest first.
	 *
	 * @param string $now   Current UTC time as a MySQL DATETIME.
	 * @param int    $limit Batch size.
	 * @return array<int, Enrollment>
	 */
	public function due( string $now, int $limit ): array;

	/**
	 * How many are due, for the engine's re-enqueue decision.
	 *
	 * @param string $now Current UTC time as a MySQL DATETIME.
	 * @return int
	 */
	public function countDue( string $now ): int;

	/**
	 * Every open enrolment for a lead.
	 *
	 * @param int $leadId Lead.
	 * @return array<int, Enrollment>
	 */
	public function openForLead( int $leadId ): array;

	/**
	 * Every open enrolment in a sequence.
	 *
	 * Bounded, because deleting a sequence with forty thousand people in
	 * it must not load forty thousand rows into one request. What is left
	 * over stops sending regardless — the engine checks the sequence
	 * first — and is closed by the next delete of the same sequence.
	 *
	 * @param int $sequenceId Sequence.
	 * @param int $limit      Batch size.
	 * @return array<int, Enrollment>
	 */
	public function openForSequence( int $sequenceId, int $limit = 500 ): array;

	/**
	 * Insert or update.
	 *
	 * @param Enrollment $enrollment Enrolment.
	 * @return Enrollment
	 */
	public function save( Enrollment $enrollment ): Enrollment;

	/**
	 * How many enrolments a sequence has in each state.
	 *
	 * @param int $sequenceId Sequence.
	 * @return array<string, int>
	 */
	public function statusCounts( int $sequenceId ): array;
}
