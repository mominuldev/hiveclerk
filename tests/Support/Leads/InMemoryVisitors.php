<?php
/**
 * Visitor storage without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Leads;

use Hiveclerk\Domain\Lead\Visitor;
use Hiveclerk\Domain\Lead\VisitorRepositoryInterface;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Visitor storage without a database.
 *
 * @internal
 */
final class InMemoryVisitors implements VisitorRepositoryInterface {

	/**
	 * Visitors by id.
	 *
	 * @var array<int, Visitor>
	 */
	public array $saved = array();

	public function find( int $id ): ?Visitor {
		return $this->saved[ $id ] ?? null;
	}

	public function findByUuid( Uuid $uuid ): ?Visitor {
		foreach ( $this->saved as $visitor ) {
			if ( $visitor->uuid->value === $uuid->value ) {
				return $visitor;
			}
		}

		return null;
	}

	public function save( Visitor $visitor ): Visitor {
		$visitor->id                 = $visitor->id ?? count( $this->saved ) + 1;
		$this->saved[ $visitor->id ] = $visitor;

		return $visitor;
	}

	public function forLead( int $leadId ): array {
		return array_values(
			array_filter(
				$this->saved,
				static fn ( Visitor $visitor ): bool => $visitor->leadId === $leadId
			)
		);
	}

	public function attachToLead( array $ids, int $leadId ): int {
		$updated = 0;

		foreach ( $ids as $id ) {
			$visitor = $this->saved[ (int) $id ] ?? null;

			if ( null !== $visitor ) {
				$visitor->leadId = $leadId;
				++$updated;
			}
		}

		return $updated;
	}

	public function reassign( int $from, int $to ): int {
		$moved = 0;

		foreach ( $this->saved as $visitor ) {
			if ( $visitor->leadId === $from ) {
				$visitor->leadId = $to;
				++$moved;
			}
		}

		return $moved;
	}

	public function detachLead( int $leadId ): int {
		$updated = 0;

		foreach ( $this->saved as $visitor ) {
			if ( $visitor->leadId === $leadId ) {
				$visitor->leadId = null;
				++$updated;
			}
		}

		return $updated;
	}

	public function deleteForLead( int $leadId ): int {
		$before = count( $this->saved );

		$this->saved = array_values(
			array_filter(
				$this->saved,
				static fn ( Visitor $visitor ): bool => $visitor->leadId !== $leadId
			)
		);

		return $before - count( $this->saved );
	}
}
