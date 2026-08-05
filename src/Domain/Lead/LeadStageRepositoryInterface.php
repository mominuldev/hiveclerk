<?php
/**
 * Pipeline stage repository contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

/**
 * Persistence for pipeline stages.
 */
interface LeadStageRepositoryInterface {

	/**
	 * Find by storage id.
	 *
	 * @param int $id Storage id.
	 * @return LeadStage|null
	 */
	public function find( int $id ): ?LeadStage;

	/**
	 * Find by slug.
	 *
	 * @param string $slug Machine name.
	 * @return LeadStage|null
	 */
	public function findBySlug( string $slug ): ?LeadStage;

	/**
	 * Every stage, left to right.
	 *
	 * @return array<int, LeadStage>
	 */
	public function all(): array;

	/**
	 * Insert or update.
	 *
	 * @param LeadStage $stage Stage.
	 * @return LeadStage
	 */
	public function save( LeadStage $stage ): LeadStage;

	/**
	 * Delete a stage.
	 *
	 * @param int $id Storage id.
	 * @return bool
	 */
	public function delete( int $id ): bool;

	/**
	 * Write a new left-to-right order.
	 *
	 * @param array<int, int> $ids Stage ids in their new order.
	 * @return void
	 */
	public function reorder( array $ids ): void;

	/**
	 * How many stages exist.
	 *
	 * @return int
	 */
	public function count(): int;
}
