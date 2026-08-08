<?php
/**
 * Workflows without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Workflows;

use DateTimeImmutable;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Domain\Workflow\TriggerEvent;
use Hiveclerk\Domain\Workflow\Workflow;
use Hiveclerk\Domain\Workflow\WorkflowRepositoryInterface;

/**
 * Workflows, in memory.
 *
 * @internal
 */
final class InMemoryWorkflows implements WorkflowRepositoryInterface {

	/**
	 * Stored workflows by id.
	 *
	 * @var array<int, Workflow>
	 */
	public array $rows = array();

	private int $nextId = 1;

	public function find( int $id ): ?Workflow {
		return $this->rows[ $id ] ?? null;
	}

	public function findByUuid( Uuid $uuid ): ?Workflow {
		foreach ( $this->rows as $workflow ) {
			if ( $workflow->uuid->value === $uuid->value ) {
				return $workflow;
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

	public function liveFor( TriggerEvent $trigger ): array {
		return array_values(
			array_filter(
				$this->rows,
				static fn ( Workflow $workflow ): bool => $workflow->isLive()
					&& $workflow->trigger === $trigger
			)
		);
	}

	public function dueSchedules( string $now, int $limit ): array {
		$due = array_filter(
			$this->rows,
			static fn ( Workflow $workflow ): bool => $workflow->isLive()
				&& $workflow->trigger->isScheduled()
				&& ( null === $workflow->nextRunAt || $workflow->nextRunAt->format( 'Y-m-d H:i:s' ) <= $now )
		);

		return array_slice( array_values( $due ), 0, $limit );
	}

	public function save( Workflow $workflow ): Workflow {
		if ( null === $workflow->id ) {
			$workflow->id = $this->nextId++;
		}

		$this->rows[ $workflow->id ] = $workflow;

		return $workflow;
	}

	public function softDelete( int $id ): bool {
		if ( isset( $this->rows[ $id ] ) ) {
			$this->rows[ $id ]->deletedAt = new DateTimeImmutable( 'now' );
		}

		return true;
	}

	public function recordRuns( int $id, int $opened, ?DateTimeImmutable $nextRunAt ): void {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return;
		}

		$this->rows[ $id ]->runCount += $opened;
		$this->rows[ $id ]->nextRunAt = $nextRunAt;
	}
}
