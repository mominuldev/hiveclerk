<?php
/**
 * Stage storage without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Leads;

use Hiveclerk\Domain\Lead\LeadStage;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;

/**
 * Stage storage without a database.
 *
 * @internal
 */
final class InMemoryStages implements LeadStageRepositoryInterface {

	/**
	 * Stages by id.
	 *
	 * @var array<int, LeadStage>
	 */
	public array $saved = array();

	/**
	 * Seed with the defaults the migration writes.
	 *
	 * @return self
	 */
	public static function withDefaults(): self {
		$repository = new self();
		$position   = 0;

		foreach ( LeadStage::defaults() as $stage ) {
			$repository->save(
				new LeadStage(
					id: null,
					name: $stage['name'],
					slug: $stage['slug'],
					color: $stage['color'],
					position: $position,
					isWon: $stage['is_won'],
					isLost: $stage['is_lost'],
				)
			);

			++$position;
		}

		return $repository;
	}

	public function find( int $id ): ?LeadStage {
		return $this->saved[ $id ] ?? null;
	}

	public function findBySlug( string $slug ): ?LeadStage {
		foreach ( $this->saved as $stage ) {
			if ( $stage->slug === $slug ) {
				return $stage;
			}
		}

		return null;
	}

	public function all(): array {
		$stages = array_values( $this->saved );

		usort(
			$stages,
			static fn ( LeadStage $a, LeadStage $b ): int => $a->position <=> $b->position
		);

		return $stages;
	}

	public function save( LeadStage $stage ): LeadStage {
		$stage->id                 = $stage->id ?? count( $this->saved ) + 1;
		$this->saved[ $stage->id ] = $stage;

		return $stage;
	}

	public function delete( int $id ): bool {
		unset( $this->saved[ $id ] );

		return true;
	}

	public function reorder( array $ids ): void {
		$position = 0;

		foreach ( $ids as $id ) {
			$stage = $this->saved[ (int) $id ] ?? null;

			if ( null !== $stage ) {
				$stage->position = $position;
			}

			++$position;
		}
	}

	public function count(): int {
		return count( $this->saved );
	}
}
