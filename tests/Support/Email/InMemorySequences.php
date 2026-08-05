<?php
/**
 * Sequences without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Email;

use Hiveclerk\Domain\Email\EmailSequence;
use Hiveclerk\Domain\Email\SequenceRepositoryInterface;
use Hiveclerk\Domain\Email\TriggerType;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Sequences, in memory.
 *
 * @internal
 */
final class InMemorySequences implements SequenceRepositoryInterface {

	/**
	 * Stored sequences by id.
	 *
	 * @var array<int, EmailSequence>
	 */
	public array $rows = array();

	private int $nextId = 1;

	public function find( int $id ): ?EmailSequence {
		return $this->rows[ $id ] ?? null;
	}

	public function findByUuid( Uuid $uuid ): ?EmailSequence {
		foreach ( $this->rows as $sequence ) {
			if ( $sequence->uuid->value === $uuid->value ) {
				return $sequence;
			}
		}

		return null;
	}

	public function paginate( Pagination $pagination ): array {
		unset( $pagination );

		return array_values( $this->rows );
	}

	public function countAll(): int {
		return count( $this->rows );
	}

	public function activeFor( TriggerType $trigger ): array {
		return array_values(
			array_filter(
				$this->rows,
				static fn ( EmailSequence $sequence ): bool => $sequence->isActive()
					&& $sequence->trigger === $trigger
			)
		);
	}

	public function save( EmailSequence $sequence ): EmailSequence {
		if ( null === $sequence->id ) {
			$sequence->id = $this->nextId++;
		}

		$this->rows[ $sequence->id ] = $sequence;

		return $sequence;
	}

	public function softDelete( int $id ): bool {
		unset( $this->rows[ $id ] );

		return true;
	}

	public function incrementEnrolled( int $id, int $by = 1 ): void {
		if ( isset( $this->rows[ $id ] ) ) {
			$this->rows[ $id ]->enrolledCount += $by;
		}
	}
}
