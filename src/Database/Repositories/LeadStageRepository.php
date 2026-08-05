<?php
/**
 * Pipeline stage repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Lead\LeadStage;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;

/**
 * Stores pipeline stages.
 */
final class LeadStageRepository extends AbstractRepository implements LeadStageRepositoryInterface {

	protected function table(): string {
		return Schema::LEAD_STAGES;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'position', 'name' );
	}

	public function find( int $id ): ?LeadStage {
		$row = $this->fetchRow( 'id = %d', array( $id ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function findBySlug( string $slug ): ?LeadStage {
		$row = $this->fetchRow( 'slug = %s', array( $slug ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function all(): array {
		// Position then id, because two stages can share a position after a
		// reorder that half-applied, and a board whose columns swap places
		// between two page loads reads as data loss.
		$table = $this->tableName();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( "SELECT * FROM `{$table}` ORDER BY position ASC, id ASC", ARRAY_A );

		return array_map(
			fn ( array $row ): LeadStage => $this->hydrate( $row ),
			is_array( $rows ) ? $rows : array()
		);
	}

	public function save( LeadStage $stage ): LeadStage {
		$data = array(
			'name'     => $stage->name,
			'slug'     => $stage->slug,
			'color'    => $stage->color,
			'position' => $stage->position,
			'is_won'   => $stage->isWon ? 1 : 0,
			'is_lost'  => $stage->isLost ? 1 : 0,
		);

		if ( null === $stage->id ) {
			$created = $stage->createdAt ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

			$data['created_at'] = $created->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

			$stage->createdAt = $created;

			$id = $this->insertRow( $data );

			if ( null === $id ) {
				return $stage;
			}

			$stage->id = $id;

			return $stage;
		}

		$this->updateRow( $stage->id, $data );

		return $stage;
	}

	public function delete( int $id ): bool {
		return $this->deleteRow( $id );
	}

	public function reorder( array $ids ): void {
		$position = 0;

		foreach ( $ids as $id ) {
			$this->updateRow( (int) $id, array( 'position' => $position ) );

			++$position;
		}
	}

	public function count(): int {
		return $this->countWhere();
	}

	/**
	 * Build a LeadStage from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return LeadStage
	 */
	private function hydrate( array $row ): LeadStage {
		$created = $row['created_at'] ?? null;

		return new LeadStage(
			id: (int) $row['id'],
			name: (string) $row['name'],
			slug: (string) $row['slug'],
			color: $this->text( $row['color'] ?? null ),
			position: (int) ( $row['position'] ?? 0 ),
			isWon: (bool) ( $row['is_won'] ?? false ),
			isLost: (bool) ( $row['is_lost'] ?? false ),
			createdAt: $this->time( $created ),
		);
	}
}
