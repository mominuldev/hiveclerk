<?php
/**
 * Embedding repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Knowledge\EmbeddingMatrix;
use Hiveclerk\Domain\Knowledge\EmbeddingRepositoryInterface;
use Hiveclerk\Domain\Knowledge\StoredEmbedding;

/**
 * Stores vectors.
 *
 * The largest table in the schema by a wide margin — 64 MB per ten
 * thousand chunks — and the only one read on every single visitor
 * message. Both facts show up in the method bodies: writes are batched
 * multi-row upserts, and reads never use `SELECT *`, because the two
 * columns stage 1 needs are 192 bytes each and the one it does not is
 * 6 KB.
 */
final class EmbeddingRepository extends AbstractRepository implements EmbeddingRepositoryInterface {

	/**
	 * Rows per multi-row INSERT.
	 *
	 * Lower than the chunk repository's fifty because each row carries a
	 * 6 KB blob. Twenty-five of them is around 150 KB of statement, which
	 * stays well inside the 4 MB `max_allowed_packet` some shared hosts
	 * still default to.
	 */
	private const INSERT_BATCH = 25;

	/**
	 * Rows read per pass when building the matrix.
	 *
	 * The matrix is assembled into one string incrementally rather than
	 * materialised as ten thousand result rows at once. Peak memory during
	 * the build is what decides whether a 50,000-chunk site can search at
	 * all inside a 96 MB budget.
	 */
	private const SCAN_BATCH = 2000;

	protected function table(): string {
		return Schema::EMBEDDINGS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'chunk_id', 'created_at' );
	}

	public function saveMany( array $embeddings ): int {
		if ( array() === $embeddings ) {
			return 0;
		}

		$written = 0;

		foreach ( array_chunk( $embeddings, self::INSERT_BATCH ) as $batch ) {
			$written += $this->insertBatch( $batch );
		}

		return $written;
	}

	public function matrix( array $sourceIds, string $provider, string $model ): EmbeddingMatrix {
		$sourceIds = $this->ids( $sourceIds );

		if ( array() === $sourceIds ) {
			return EmbeddingMatrix::empty();
		}

		$table        = $this->tableName();
		$placeholders = implode( ', ', array_fill( 0, count( $sourceIds ), '%d' ) );

		$ids    = array();
		$bits   = '';
		$width  = 0;
		$lastId = 0;

		// Keyset pagination on the primary key rather than LIMIT/OFFSET.
		// OFFSET makes MySQL walk and discard every earlier row, so the
		// last page of a fifty-thousand-chunk scan costs fifty thousand
		// row reads to return two thousand.
		while ( true ) {
			$params = array_merge( $sourceIds, array( $provider, $model, $lastId, self::SCAN_BATCH ) );

			$sql = $this->db->prepare(
				"SELECT id, chunk_id, embedding_bits
				 FROM `{$table}`
				 WHERE source_id IN ({$placeholders})
				   AND provider = %s
				   AND model = %s
				   AND id > %d
				 ORDER BY id ASC
				 LIMIT %d",
				...$params
			);

			if ( ! is_string( $sql ) ) {
				break;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $this->db->get_results( $sql, ARRAY_A );

			if ( ! is_array( $rows ) || array() === $rows ) {
				break;
			}

			foreach ( $rows as $row ) {
				$packed = (string) ( $row['embedding_bits'] ?? '' );

				if ( '' === $packed ) {
					continue;
				}

				if ( 0 === $width ) {
					$width = strlen( $packed );
				}

				// A row of a different width belongs to a different model
				// than the one this matrix is for. Concatenating it would
				// shift every subsequent row's offset and silently corrupt
				// the whole scan, so it is skipped rather than trusted.
				if ( strlen( $packed ) !== $width ) {
					continue;
				}

				$ids[] = (int) ( $row['chunk_id'] ?? 0 );
				$bits .= $packed;
			}

			$lastId = (int) ( end( $rows )['id'] ?? 0 );

			if ( count( $rows ) < self::SCAN_BATCH ) {
				break;
			}
		}

		return new EmbeddingMatrix( $ids, $bits, $width );
	}

	public function exact( array $chunkIds, string $provider, string $model ): array {
		$chunkIds = $this->ids( $chunkIds );

		if ( array() === $chunkIds ) {
			return array();
		}

		$table        = $this->tableName();
		$placeholders = implode( ', ', array_fill( 0, count( $chunkIds ), '%d' ) );

		$sql = $this->db->prepare(
			"SELECT chunk_id, source_id, provider, model, dimensions, embedding_f32, embedding_bits, norm
			 FROM `{$table}`
			 WHERE chunk_id IN ({$placeholders}) AND provider = %s AND model = %s",
			...array_merge( $chunkIds, array( $provider, $model ) )
		);

		if ( ! is_string( $sql ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$loaded = array();

		foreach ( $rows as $row ) {
			$chunkId = (int) ( $row['chunk_id'] ?? 0 );

			if ( $chunkId <= 0 ) {
				continue;
			}

			$loaded[ $chunkId ] = new StoredEmbedding(
				chunkId: $chunkId,
				sourceId: (int) ( $row['source_id'] ?? 0 ),
				provider: (string) ( $row['provider'] ?? '' ),
				model: (string) ( $row['model'] ?? '' ),
				dimensions: (int) ( $row['dimensions'] ?? 0 ),
				f32: (string) ( $row['embedding_f32'] ?? '' ),
				bits: (string) ( $row['embedding_bits'] ?? '' ),
				norm: (float) ( $row['norm'] ?? 0.0 )
			);
		}

		return $loaded;
	}

	public function pendingChunkIds( int $sourceId, string $provider, string $model, int $limit ): array {
		$chunks     = Schema::table( Schema::CHUNKS );
		$embeddings = $this->tableName();

		$sql = $this->db->prepare(
			"SELECT c.id
			 FROM `{$chunks}` c
			 LEFT JOIN `{$embeddings}` e
			   ON e.chunk_id = c.id AND e.provider = %s AND e.model = %s
			 WHERE c.source_id = %d AND e.id IS NULL
			 ORDER BY c.id ASC
			 LIMIT %d",
			$provider,
			$model,
			$sourceId,
			max( 1, $limit )
		);

		if ( ! is_string( $sql ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $this->db->get_col( $sql );

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	public function countPending( int $sourceId, string $provider, string $model ): int {
		$chunks     = Schema::table( Schema::CHUNKS );
		$embeddings = $this->tableName();

		$sql = $this->db->prepare(
			"SELECT COUNT(*)
			 FROM `{$chunks}` c
			 LEFT JOIN `{$embeddings}` e
			   ON e.chunk_id = c.id AND e.provider = %s AND e.model = %s
			 WHERE c.source_id = %d AND e.id IS NULL",
			$provider,
			$model,
			$sourceId
		);

		if ( ! is_string( $sql ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->get_var( $sql );
	}

	public function countForSource( int $sourceId ): int {
		return $this->countWhere( 'source_id = %d', array( $sourceId ) );
	}

	public function modelsForSource( int $sourceId ): array {
		$table = $this->tableName();

		$sql = $this->db->prepare(
			"SELECT provider, model, dimensions, COUNT(*) AS total
			 FROM `{$table}`
			 WHERE source_id = %d
			 GROUP BY provider, model, dimensions
			 ORDER BY total DESC",
			$sourceId
		);

		if ( ! is_string( $sql ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static fn ( array $row ): array => array(
				'provider'   => (string) ( $row['provider'] ?? '' ),
				'model'      => (string) ( $row['model'] ?? '' ),
				'dimensions' => (int) ( $row['dimensions'] ?? 0 ),
				'count'      => (int) ( $row['total'] ?? 0 ),
			),
			$rows
		);
	}

	public function deleteForChunks( array $chunkIds ): int {
		$chunkIds = $this->ids( $chunkIds );

		if ( array() === $chunkIds ) {
			return 0;
		}

		$table        = $this->tableName();
		$placeholders = implode( ', ', array_fill( 0, count( $chunkIds ), '%d' ) );

		return $this->execute( "DELETE FROM `{$table}` WHERE chunk_id IN ({$placeholders})", $chunkIds )
			? count( $chunkIds )
			: 0;
	}

	public function deleteForSource( int $sourceId, ?string $provider = null, ?string $model = null ): int {
		$table  = $this->tableName();
		$where  = 'source_id = %d';
		$params = array( $sourceId );

		if ( null !== $provider ) {
			$where   .= ' AND provider = %s';
			$params[] = $provider;
		}

		if ( null !== $model ) {
			$where   .= ' AND model = %s';
			$params[] = $model;
		}

		$sql = $this->db->prepare( "DELETE FROM `{$table}` WHERE {$where}", ...$params );

		if ( ! is_string( $sql ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->db->query( $sql );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Insert or replace a batch of vectors.
	 *
	 * @param array<int, StoredEmbedding> $batch Rows.
	 * @return int
	 */
	private function insertBatch( array $batch ): int {
		$table  = $this->tableName();
		$now    = $this->now();
		$rows   = array();
		$values = array();

		foreach ( $batch as $embedding ) {
			$rows[]   = '(%d, %d, %s, %s, %d, %s, %s, %f, %s)';
			$values[] = $embedding->chunkId;
			$values[] = $embedding->sourceId;
			$values[] = $embedding->provider;
			$values[] = $embedding->model;
			$values[] = $embedding->dimensions;
			$values[] = $embedding->f32;
			$values[] = $embedding->bits;
			$values[] = $embedding->norm;
			$values[] = $now;
		}

		/*
		 * ON DUPLICATE KEY rather than delete-then-insert, against the
		 * (chunk_id, provider, model) unique key. Re-embedding a chunk with
		 * the same model has to be idempotent: the embedding job is
		 * re-enqueued after every batch and a host that kills it mid-run
		 * will run the same batch again, which must not leave the chunk
		 * with two vectors or, worse, none.
		 */
		$written = $this->execute(
			"INSERT INTO `{$table}`
			 (chunk_id, source_id, provider, model, dimensions,
			  embedding_f32, embedding_bits, norm, created_at)
			 VALUES " . implode( ', ', $rows ) . '
			 ON DUPLICATE KEY UPDATE
			   source_id = VALUES(source_id),
			   dimensions = VALUES(dimensions),
			   embedding_f32 = VALUES(embedding_f32),
			   embedding_bits = VALUES(embedding_bits),
			   norm = VALUES(norm),
			   created_at = VALUES(created_at)',
			$values
		);

		return $written ? count( $batch ) : 0;
	}

	/**
	 * Clean a list of ids.
	 *
	 * @param array<int, int> $ids Raw ids.
	 * @return array<int, int>
	 */
	private function ids( array $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}
}
