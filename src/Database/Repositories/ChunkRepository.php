<?php
/**
 * Chunk repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Knowledge\Chunk;
use Hiveclerk\Domain\Knowledge\ChunkRepositoryInterface;

/**
 * Stores chunks.
 */
final class ChunkRepository extends AbstractRepository implements ChunkRepositoryInterface {

	/**
	 * Rows per multi-row INSERT.
	 *
	 * A document can produce hundreds of chunks and inserting them one at
	 * a time is one round trip each. Batching too hard runs into
	 * max_allowed_packet, which defaults to 4 MB on some shared hosts —
	 * and a chunk is up to a few kilobytes.
	 */
	private const INSERT_BATCH = 50;

	protected function table(): string {
		return Schema::CHUNKS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'chunk_index', 'token_count' );
	}

	public function find( int $id ): ?Chunk {
		$row = $this->fetchRow( 'id = %d', array( $id ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function findMany( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$table = $this->tableName();

		// Placeholders are generated to match the argument count rather
		// than interpolating the ids: the values are already integers, but
		// building the list by hand is how an IN clause becomes the one
		// unprepared query in a codebase.
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$sql = $this->db->prepare(
			"SELECT * FROM `{$table}` WHERE id IN ({$placeholders})",
			...$ids
		);

		if ( ! is_string( $sql ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( fn ( array $row ): Chunk => $this->hydrate( $row ), $rows );
	}

	public function forDocument( int $documentId ): array {
		$rows = $this->fetchAll( 'document_id = %d', array( $documentId ), 'chunk_index', 'ASC' );

		return array_map( fn ( array $row ): Chunk => $this->hydrate( $row ), $rows );
	}

	public function replaceForDocument( int $documentId, array $chunks ): array {
		$embeddings = Schema::table( Schema::EMBEDDINGS );
		$table      = $this->tableName();

		// Vectors for the old chunks go first. They are keyed on chunk id,
		// so once the chunks are gone there is no way to find them again
		// and they become permanent orphans in the largest table.
		$this->execute(
			"DELETE e FROM `{$embeddings}` e
			 INNER JOIN `{$table}` c ON c.id = e.chunk_id
			 WHERE c.document_id = %d",
			array( $documentId )
		);

		$this->execute( "DELETE FROM `{$table}` WHERE document_id = %d", array( $documentId ) );

		foreach ( array_chunk( $chunks, self::INSERT_BATCH ) as $batch ) {
			$this->insertBatch( $batch );
		}

		return $this->forDocument( $documentId );
	}

	public function searchKeyword( string $query, array $sourceIds, int $limit ): array {
		$sourceIds = array_values( array_unique( array_filter( array_map( 'intval', $sourceIds ) ) ) );
		$query     = trim( $query );

		if ( array() === $sourceIds || '' === $query ) {
			return array();
		}

		$table        = $this->tableName();
		$placeholders = implode( ', ', array_fill( 0, count( $sourceIds ), '%d' ) );

		/*
		 * Natural-language mode, not boolean mode. Boolean mode gives the
		 * query string operator meaning — a leading `-` excludes, `*`
		 * truncates, `"` groups — so a visitor asking about "e-bikes" or
		 * typing an unbalanced quote either gets nothing back or gets a
		 * syntax error from MySQL. Natural-language mode treats the whole
		 * string as text, which is what a question is.
		 *
		 * The relevance figure InnoDB returns is its BM25 variant. It is
		 * unbounded and not comparable to a cosine similarity, which is
		 * why fusion combines ranks rather than scores.
		 */
		$sql = $this->db->prepare(
			"SELECT id, MATCH(content) AGAINST(%s IN NATURAL LANGUAGE MODE) AS score
			 FROM `{$table}`
			 WHERE source_id IN ({$placeholders})
			   AND MATCH(content) AGAINST(%s IN NATURAL LANGUAGE MODE)
			 ORDER BY score DESC
			 LIMIT %d",
			...array_merge( array( $query ), $sourceIds, array( $query, max( 1, $limit ) ) )
		);

		if ( ! is_string( $sql ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$matches = array();

		foreach ( $rows as $row ) {
			$matches[] = array(
				'chunk_id' => (int) ( $row['id'] ?? 0 ),
				'score'    => (float) ( $row['score'] ?? 0.0 ),
			);
		}

		return $matches;
	}

	public function countForSource( int $sourceId ): int {
		return $this->countWhere( 'source_id = %d', array( $sourceId ) );
	}

	public function tokensForSource( int $sourceId ): int {
		$table = $this->tableName();

		$sql = $this->db->prepare(
			"SELECT COALESCE(SUM(token_count), 0) FROM `{$table}` WHERE source_id = %d",
			$sourceId
		);

		if ( ! is_string( $sql ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->get_var( $sql );
	}

	public function deleteForSource( int $sourceId ): int {
		$embeddings = Schema::table( Schema::EMBEDDINGS );
		$table      = $this->tableName();

		$this->execute( "DELETE FROM `{$embeddings}` WHERE source_id = %d", array( $sourceId ) );

		$sql = $this->db->prepare( "DELETE FROM `{$table}` WHERE source_id = %d", $sourceId );

		if ( ! is_string( $sql ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->db->query( $sql );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Insert several chunks in one statement.
	 *
	 * @param array<int, Chunk> $chunks Chunks.
	 * @return void
	 */
	private function insertBatch( array $chunks ): void {
		if ( array() === $chunks ) {
			return;
		}

		$table  = $this->tableName();
		$now    = $this->now();
		$rows   = array();
		$values = array();

		foreach ( $chunks as $chunk ) {
			$rows[]   = '(%d, %d, %d, %s, %s, %s, %d, %d, %d, %s)';
			$values[] = $chunk->documentId;
			$values[] = $chunk->sourceId;
			$values[] = $chunk->chunkIndex;
			$values[] = $chunk->content;
			$values[] = $chunk->contentHash;
			$values[] = $chunk->path();
			$values[] = $chunk->tokenCount;
			$values[] = $chunk->charStart;
			$values[] = $chunk->charEnd;
			$values[] = $now;
		}

		$this->execute(
			"INSERT INTO `{$table}`
			 (document_id, source_id, chunk_index, content, content_hash,
			  heading_path, token_count, char_start, char_end, created_at)
			 VALUES " . implode( ', ', $rows ),
			$values
		);
	}

	/**
	 * Build a Chunk from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return Chunk
	 */
	private function hydrate( array $row ): Chunk {
		return new Chunk(
			id: isset( $row['id'] ) ? (int) $row['id'] : null,
			documentId: (int) ( $row['document_id'] ?? 0 ),
			sourceId: (int) ( $row['source_id'] ?? 0 ),
			chunkIndex: (int) ( $row['chunk_index'] ?? 0 ),
			content: (string) ( $row['content'] ?? '' ),
			contentHash: (string) ( $row['content_hash'] ?? '' ),
			headingPath: Chunk::splitPath( isset( $row['heading_path'] ) ? (string) $row['heading_path'] : null ),
			tokenCount: (int) ( $row['token_count'] ?? 0 ),
			charStart: (int) ( $row['char_start'] ?? 0 ),
			charEnd: (int) ( $row['char_end'] ?? 0 ),
		);
	}
}
