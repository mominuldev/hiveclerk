<?php
/**
 * Knowledge source repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Knowledge\KnowledgeSource;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
use Hiveclerk\Domain\Knowledge\SourceStatus;
use Hiveclerk\Domain\Knowledge\SourceType;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Stores knowledge sources.
 */
final class KnowledgeSourceRepository extends AbstractRepository implements KnowledgeSourceRepositoryInterface {

	protected function table(): string {
		return Schema::KNOWLEDGE_SOURCES;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'name', 'status', 'chunk_count', 'last_synced_at', 'created_at' );
	}

	public function find( int $id ): ?KnowledgeSource {
		$row = $this->fetchRow( 'id = %d AND deleted_at IS NULL', array( $id ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function findByUuid( Uuid $uuid ): ?KnowledgeSource {
		$row = $this->fetchRow( 'uuid = %s AND deleted_at IS NULL', array( $uuid->value ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function paginate( Pagination $pagination ): array {
		$rows = $this->fetchAll(
			'deleted_at IS NULL',
			array(),
			'created_at',
			'DESC',
			$pagination->perPage,
			$pagination->offset()
		);

		return array_map( fn ( array $row ): KnowledgeSource => $this->hydrate( $row ), $rows );
	}

	public function count(): int {
		return $this->countWhere( 'deleted_at IS NULL' );
	}

	public function forAgent( int $agentId ): array {
		$sources = $this->tableName();
		$pivot   = Schema::table( Schema::AGENT_SOURCES );

		$sql = $this->db->prepare(
			"SELECT s.* FROM `{$sources}` s
			 INNER JOIN `{$pivot}` p ON p.source_id = s.id
			 WHERE p.agent_id = %d AND s.deleted_at IS NULL
			 ORDER BY p.priority DESC, s.id ASC",
			$agentId
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( fn ( array $row ): KnowledgeSource => $this->hydrate( $row ), $rows );
	}

	public function totalChunks(): int {
		$table = $this->tableName();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->get_var(
			"SELECT COALESCE(SUM(chunk_count), 0) FROM `{$table}` WHERE deleted_at IS NULL"
		);
	}

	public function save( KnowledgeSource $source ): KnowledgeSource {
		$data = array(
			'uuid'             => $source->uuid->value,
			'name'             => $source->name,
			'type'             => $source->type->value,
			'status'           => $source->status->value,
			'config'           => $this->encodeJson( $source->config ),
			'embed_provider'   => $source->embedProvider,
			'embed_model'      => $source->embedModel,
			'embed_dimensions' => $source->embedDimensions,
			'document_count'   => $source->documentCount,
			'chunk_count'      => $source->chunkCount,
			'token_count'      => $source->tokenCount,
			'sync_schedule'    => $source->syncSchedule,
			'last_error'       => $source->lastError,
			'progress'         => $this->encodeJson( $source->progress ),
			'last_synced_at'   => $source->lastSyncedAt,
			'updated_at'       => $this->now(),
		);

		if ( null === $source->id ) {
			$data['created_at'] = $this->now();

			$id = $this->insertRow( $data );

			if ( null === $id ) {
				return $source;
			}

			$source->id = $id;

			return $source;
		}

		$this->updateRow( $source->id, $data );

		return $source;
	}

	public function delete( int $id ): bool {
		return $this->updateRow( $id, array( 'deleted_at' => $this->now() ) );
	}

	/**
	 * Build a KnowledgeSource from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return KnowledgeSource
	 */
	private function hydrate( array $row ): KnowledgeSource {
		return new KnowledgeSource(
			id: isset( $row['id'] ) ? (int) $row['id'] : null,
			uuid: new Uuid( (string) ( $row['uuid'] ?? '' ) ),
			name: (string) ( $row['name'] ?? '' ),
			type: SourceType::fromStorage( isset( $row['type'] ) ? (string) $row['type'] : null ),
			status: SourceStatus::fromStorage( isset( $row['status'] ) ? (string) $row['status'] : null ),
			config: $this->json( $row['config'] ?? null ),
			embedProvider: isset( $row['embed_provider'] ) ? (string) $row['embed_provider'] : null,
			embedModel: isset( $row['embed_model'] ) ? (string) $row['embed_model'] : null,
			embedDimensions: isset( $row['embed_dimensions'] ) ? (int) $row['embed_dimensions'] : null,
			documentCount: (int) ( $row['document_count'] ?? 0 ),
			chunkCount: (int) ( $row['chunk_count'] ?? 0 ),
			tokenCount: (int) ( $row['token_count'] ?? 0 ),
			syncSchedule: (string) ( $row['sync_schedule'] ?? 'manual' ),
			lastError: isset( $row['last_error'] ) ? (string) $row['last_error'] : null,
			progress: $this->json( $row['progress'] ?? null ),
			lastSyncedAt: isset( $row['last_synced_at'] ) ? (string) $row['last_synced_at'] : null,
		);
	}
}
