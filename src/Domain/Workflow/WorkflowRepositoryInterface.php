<?php
/**
 * Workflow storage port.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

use DateTimeImmutable;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Reads and writes workflows.
 */
interface WorkflowRepositoryInterface {

	/**
	 * Find by storage id.
	 *
	 * @param int $id Storage id.
	 * @return Workflow|null
	 */
	public function find( int $id ): ?Workflow;

	/**
	 * Find by public identifier.
	 *
	 * @param Uuid $uuid Public identifier.
	 * @return Workflow|null
	 */
	public function findByUuid( Uuid $uuid ): ?Workflow;

	/**
	 * One page of workflows, newest first.
	 *
	 * @param Pagination $pagination Page request.
	 * @return array<int, Workflow>
	 */
	public function paginate( Pagination $pagination ): array;

	/**
	 * How many workflows exist.
	 *
	 * @return int
	 */
	public function countAll(): int;

	/**
	 * Every live workflow listening for one event.
	 *
	 * @param TriggerEvent $trigger Event.
	 * @return array<int, Workflow>
	 */
	public function liveFor( TriggerEvent $trigger ): array;

	/**
	 * Every live scheduled workflow whose next sweep is due.
	 *
	 * @param string $now   Current UTC time as a MySQL DATETIME.
	 * @param int    $limit Most to return.
	 * @return array<int, Workflow>
	 */
	public function dueSchedules( string $now, int $limit ): array;

	/**
	 * Insert or update.
	 *
	 * @param Workflow $workflow Workflow.
	 * @return Workflow
	 */
	public function save( Workflow $workflow ): Workflow;

	/**
	 * Mark deleted without removing the row.
	 *
	 * @param int $id Storage id.
	 * @return bool
	 */
	public function softDelete( int $id ): bool;

	/**
	 * Record that a sweep or trigger opened runs.
	 *
	 * @param int                    $id        Storage id.
	 * @param int                    $opened    Runs opened.
	 * @param DateTimeImmutable|null $nextRunAt Next scheduled sweep, UTC.
	 * @return void
	 */
	public function recordRuns( int $id, int $opened, ?DateTimeImmutable $nextRunAt ): void;
}
