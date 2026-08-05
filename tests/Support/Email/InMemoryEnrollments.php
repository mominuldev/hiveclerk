<?php
/**
 * Enrolments without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Email;

use Hiveclerk\Domain\Email\Enrollment;
use Hiveclerk\Domain\Email\EnrollmentRepositoryInterface;

/**
 * Enrolments, in memory.
 *
 * @internal
 */
final class InMemoryEnrollments implements EnrollmentRepositoryInterface {

	/**
	 * Stored enrolments by id.
	 *
	 * @var array<int, Enrollment>
	 */
	public array $rows = array();

	private int $nextId = 1;

	public function find( int $id ): ?Enrollment {
		return $this->rows[ $id ] ?? null;
	}

	public function findFor( int $sequenceId, int $leadId ): ?Enrollment {
		foreach ( $this->rows as $enrollment ) {
			if ( $enrollment->sequenceId === $sequenceId && $enrollment->leadId === $leadId ) {
				return $enrollment;
			}
		}

		return null;
	}

	public function due( string $now, int $limit ): array {
		$due = array_values(
			array_filter(
				$this->rows,
				static fn ( Enrollment $enrollment ): bool => $enrollment->status->isOpen()
					&& null !== $enrollment->nextSendAt
					&& $enrollment->nextSendAt->format( 'Y-m-d H:i:s' ) <= $now
			)
		);

		return array_slice( $due, 0, $limit );
	}

	public function countDue( string $now ): int {
		return count( $this->due( $now, PHP_INT_MAX ) );
	}

	public function openForLead( int $leadId ): array {
		return array_values(
			array_filter(
				$this->rows,
				static fn ( Enrollment $enrollment ): bool => $enrollment->leadId === $leadId
					&& $enrollment->status->isOpen()
			)
		);
	}

	public function openForSequence( int $sequenceId, int $limit = 500 ): array {
		return array_slice(
			array_values(
				array_filter(
					$this->rows,
					static fn ( Enrollment $enrollment ): bool => $enrollment->sequenceId === $sequenceId
						&& $enrollment->status->isOpen()
				)
			),
			0,
			$limit
		);
	}

	public function save( Enrollment $enrollment ): Enrollment {
		if ( null === $enrollment->id ) {
			$enrollment->id = $this->nextId++;
		}

		$this->rows[ $enrollment->id ] = $enrollment;

		return $enrollment;
	}

	public function statusCounts( int $sequenceId ): array {
		$counts = array();

		foreach ( $this->rows as $enrollment ) {
			if ( $enrollment->sequenceId !== $sequenceId ) {
				continue;
			}

			$key            = $enrollment->status->value;
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}

		return $counts;
	}
}
