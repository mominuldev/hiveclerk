<?php
/**
 * Lead storage without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Leads;

use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\ScoreBand;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Lead storage without a database.
 *
 * @internal
 */
final class InMemoryLeads implements LeadRepositoryInterface {

	/**
	 * Leads by id.
	 *
	 * @var array<int, Lead>
	 */
	public array $saved = array();

	/**
	 * How many times updateScore() was called.
	 *
	 * @var int
	 */
	public int $scoreWrites = 0;

	public function find( int $id ): ?Lead {
		return $this->saved[ $id ] ?? null;
	}

	public function findByUuid( Uuid $uuid ): ?Lead {
		foreach ( $this->saved as $lead ) {
			if ( $lead->uuid->value === $uuid->value ) {
				return $lead;
			}
		}

		return null;
	}

	public function findByEmailHash( string $hash ): ?Lead {
		foreach ( $this->saved as $lead ) {
			if ( null !== $lead->emailHash && $lead->emailHash === $hash ) {
				return $lead;
			}
		}

		return null;
	}

	public function paginate(
		Pagination $pagination,
		array $filters = array(),
		string $orderBy = 'created_at',
		string $order = 'DESC'
	): array {
		return array_values( $this->saved );
	}

	public function count( array $filters = array() ): int {
		return count( $this->saved );
	}

	public function countsByStage( array $filters = array() ): array {
		$counts = array();

		foreach ( $this->saved as $lead ) {
			$key            = $lead->stageId ?? 0;
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}

		return $counts;
	}

	public function batch( array $filters, int $limit, int $offset ): array {
		return array_slice( array_values( $this->saved ), $offset, $limit );
	}

	public function save( Lead $lead ): Lead {
		$lead->id                 = $lead->id ?? count( $this->saved ) + 1;
		$this->saved[ $lead->id ] = $lead;

		return $lead;
	}

	public function updateScore( int $id, int $score, ScoreBand $band ): void {
		++$this->scoreWrites;

		$lead = $this->saved[ $id ] ?? null;

		if ( null !== $lead ) {
			$lead->score = $score;
			$lead->band  = $band;
		}
	}

	public function reassignStage( int $from, ?int $to ): int {
		$moved = 0;

		foreach ( $this->saved as $lead ) {
			if ( $lead->stageId === $from ) {
				$lead->stageId = $to;
				++$moved;
			}
		}

		return $moved;
	}

	public function delete( int $id ): bool {
		unset( $this->saved[ $id ] );

		return true;
	}
}
