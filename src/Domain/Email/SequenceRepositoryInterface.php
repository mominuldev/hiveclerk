<?php
/**
 * Sequence storage contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Where sequences live.
 */
interface SequenceRepositoryInterface {

	/**
	 * One sequence by id.
	 *
	 * @param int $id Storage id.
	 * @return EmailSequence|null
	 */
	public function find( int $id ): ?EmailSequence;

	/**
	 * One sequence by public identifier.
	 *
	 * @param Uuid $uuid Public identifier.
	 * @return EmailSequence|null
	 */
	public function findByUuid( Uuid $uuid ): ?EmailSequence;

	/**
	 * A page of sequences, newest first.
	 *
	 * @param Pagination $pagination Page request.
	 * @return array<int, EmailSequence>
	 */
	public function paginate( Pagination $pagination ): array;

	/**
	 * How many sequences exist, excluding deleted ones.
	 *
	 * @return int
	 */
	public function countAll(): int;

	/**
	 * Every active sequence with a given trigger.
	 *
	 * @param TriggerType $trigger Trigger.
	 * @return array<int, EmailSequence>
	 */
	public function activeFor( TriggerType $trigger ): array;

	/**
	 * Insert or update.
	 *
	 * @param EmailSequence $sequence Sequence.
	 * @return EmailSequence
	 */
	public function save( EmailSequence $sequence ): EmailSequence;

	/**
	 * Soft-delete a sequence.
	 *
	 * Soft, because enrolments and log rows point at it and a hard delete
	 * would leave an email log full of rows naming a sequence that cannot
	 * be looked up.
	 *
	 * @param int $id Storage id.
	 * @return bool
	 */
	public function softDelete( int $id ): bool;

	/**
	 * Add to the running enrolment total.
	 *
	 * @param int $id Storage id.
	 * @param int $by Amount.
	 * @return void
	 */
	public function incrementEnrolled( int $id, int $by = 1 ): void;
}
