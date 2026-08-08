<?php
/**
 * Workflow repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use DateTimeImmutable;
use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Domain\Workflow\TriggerEvent;
use Hiveclerk\Domain\Workflow\Workflow;
use Hiveclerk\Domain\Workflow\WorkflowGraph;
use Hiveclerk\Domain\Workflow\WorkflowRepositoryInterface;
use Hiveclerk\Domain\Workflow\WorkflowStatus;

/**
 * Stores workflows.
 */
final class WorkflowRepository extends AbstractRepository implements WorkflowRepositoryInterface {

	protected function table(): string {
		return Schema::WORKFLOWS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'name', 'status', 'created_at', 'updated_at', 'last_run_at' );
	}

	public function find( int $id ): ?Workflow {
		$row = $this->fetchRow( 'id = %d', array( $id ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function findByUuid( Uuid $uuid ): ?Workflow {
		$row = $this->fetchRow( 'uuid = %s', array( $uuid->value ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function paginate( Pagination $pagination ): array {
		return array_map(
			fn ( array $row ): Workflow => $this->hydrate( $row ),
			$this->fetchAll(
				'deleted_at IS NULL',
				array(),
				'id',
				'DESC',
				$pagination->perPage,
				$pagination->offset()
			)
		);
	}

	public function countAll(): int {
		return $this->countWhere( 'deleted_at IS NULL' );
	}

	public function liveFor( TriggerEvent $trigger ): array {
		return array_map(
			fn ( array $row ): Workflow => $this->hydrate( $row ),
			$this->fetchAll(
				'deleted_at IS NULL AND status = %s AND trigger_event = %s',
				array( WorkflowStatus::Active->value, $trigger->value ),
				'id',
				'ASC'
			)
		);
	}

	public function dueSchedules( string $now, int $limit ): array {
		return array_map(
			fn ( array $row ): Workflow => $this->hydrate( $row ),
			$this->fetchAll(
				'deleted_at IS NULL AND status = %s AND trigger_event = %s'
					. ' AND ( next_run_at IS NULL OR next_run_at <= %s )',
				array( WorkflowStatus::Active->value, TriggerEvent::Schedule->value, $now ),
				'id',
				'ASC',
				$limit
			)
		);
	}

	public function save( Workflow $workflow ): Workflow {
		$data = array(
			'name'           => $workflow->name,
			'status'         => $workflow->status->value,
			'trigger_event'  => $workflow->trigger->value,
			'trigger_config' => $this->encodeJson( $workflow->triggerConfig ),
			'graph'          => $this->encodeJson( $workflow->graph->toArray() ),
			'runs_once'      => $workflow->runsOnce ? 1 : 0,
			'next_run_at'    => $this->stamp( $workflow->nextRunAt ),
			'deleted_at'     => $this->stamp( $workflow->deletedAt ),
			'updated_at'     => $this->now(),
		);

		if ( null === $workflow->id ) {
			$data['uuid']       = $workflow->uuid->value;
			$data['run_count']  = $workflow->runCount;
			$data['created_at'] = $this->now();

			$id = $this->insertRow( $data );

			if ( null === $id ) {
				return $workflow;
			}

			$workflow->id = $id;

			return $workflow;
		}

		$this->updateRow( $workflow->id, $data );

		return $workflow;
	}

	public function softDelete( int $id ): bool {
		return $this->updateRow( $id, array( 'deleted_at' => $this->now() ) );
	}

	public function recordRuns( int $id, int $opened, ?DateTimeImmutable $nextRunAt ): void {
		$table = $this->tableName();

		// Incremented in SQL for the same reason the sequence counter is:
		// two triggers landing in the same second would each read the same
		// total and write it back plus one.
		$this->execute(
			"UPDATE `{$table}` SET run_count = run_count + %d, last_run_at = %s, next_run_at = %s WHERE id = %d",
			array( max( 0, $opened ), $this->now(), $this->stamp( $nextRunAt ), $id )
		);
	}

	/**
	 * Build a Workflow from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return Workflow
	 */
	private function hydrate( array $row ): Workflow {
		return new Workflow(
			id: (int) $row['id'],
			uuid: new Uuid( (string) $row['uuid'] ),
			name: (string) $row['name'],
			status: WorkflowStatus::fromStorage( $this->text( $row['status'] ?? null ) ),
			trigger: TriggerEvent::fromStorage( $this->text( $row['trigger_event'] ?? null ) ),
			triggerConfig: $this->json( $row['trigger_config'] ?? null ),
			graph: WorkflowGraph::fromArray( $this->json( $row['graph'] ?? null ) ),
			runsOnce: 1 === (int) ( $row['runs_once'] ?? 1 ),
			nextRunAt: $this->time( $row['next_run_at'] ?? null ),
			runCount: (int) ( $row['run_count'] ?? 0 ),
			lastRunAt: $this->time( $row['last_run_at'] ?? null ),
			createdAt: $this->time( $row['created_at'] ?? null ),
			updatedAt: $this->time( $row['updated_at'] ?? null ),
			deletedAt: $this->time( $row['deleted_at'] ?? null ),
		);
	}
}
