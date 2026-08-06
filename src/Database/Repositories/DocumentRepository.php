<?php
/**
 * Document repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Knowledge\Document;
use Hiveclerk\Domain\Knowledge\DocumentRepositoryInterface;
use Hiveclerk\Domain\Knowledge\DocumentSummary;
use Hiveclerk\Domain\Shared\Pagination;

/**
 * Stores documents.
 */
final class DocumentRepository extends AbstractRepository implements DocumentRepositoryInterface {

	protected function table(): string {
		return Schema::DOCUMENTS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'title', 'status', 'token_count', 'indexed_at', 'created_at' );
	}

	public function find( int $id ): ?Document {
		$row = $this->fetchRow( 'id = %d', array( $id ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function findByExternalId( int $sourceId, string $externalId ): ?Document {
		$row = $this->fetchRow(
			'source_id = %d AND external_id = %s',
			array( $sourceId, $externalId )
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	public function forSource( int $sourceId, Pagination $pagination ): array {
		$table = $this->tableName();

		/*
		 * Named columns rather than `fetchAll()`, which is `SELECT *`. The
		 * body is a LONGTEXT this list never shows, and reading twenty of
		 * them per page is the single most wasteful query the admin makes.
		 * The list is literal — no part of it comes from the request —
		 * which is the same rule the sortable-column allowlist follows and
		 * the reason an identifier position is safe here.
		 */
		$sql = $this->db->prepare(
			"SELECT id, title, url, token_count, chunk_count, status, metadata
				FROM `{$table}`
				WHERE source_id = %d
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
			$sourceId,
			$pagination->perPage,
			$pagination->offset()
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
			fn ( array $row ): DocumentSummary => new DocumentSummary(
				id: (int) ( $row['id'] ?? 0 ),
				title: (string) ( $row['title'] ?? '' ),
				url: (string) ( $row['url'] ?? '' ),
				tokenCount: (int) ( $row['token_count'] ?? 0 ),
				chunkCount: (int) ( $row['chunk_count'] ?? 0 ),
				status: (string) ( $row['status'] ?? 'pending' ),
				metadata: $this->json( $row['metadata'] ?? null ),
			),
			$rows
		);
	}

	public function countForSource( int $sourceId ): int {
		return $this->countWhere( 'source_id = %d', array( $sourceId ) );
	}

	public function titles( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$table        = $this->tableName();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$sql = $this->db->prepare(
			"SELECT id, title, url FROM `{$table}` WHERE id IN ({$placeholders})",
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

		$titles = array();

		foreach ( $rows as $row ) {
			$url = isset( $row['url'] ) ? (string) $row['url'] : '';

			$titles[ (int) ( $row['id'] ?? 0 ) ] = array(
				'title' => (string) ( $row['title'] ?? '' ),
				'url'   => '' === $url ? null : $url,
			);
		}

		return $titles;
	}

	public function externalIds( int $sourceId ): array {
		$table = $this->tableName();

		$sql = $this->db->prepare(
			"SELECT external_id, id FROM `{$table}` WHERE source_id = %d",
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

		$map = array();

		foreach ( $rows as $row ) {
			$map[ (string) ( $row['external_id'] ?? '' ) ] = (int) ( $row['id'] ?? 0 );
		}

		return $map;
	}

	public function save( Document $document ): Document {
		$data = array(
			'source_id'    => $document->sourceId,
			'external_id'  => $document->externalId,
			'url'          => $document->url,
			'title'        => $document->title,
			'content'      => $document->content,
			'content_hash' => $document->contentHash,
			'language'     => $document->language,
			'metadata'     => $this->encodeJson( $document->metadata ),
			'token_count'  => $document->tokenCount,
			'chunk_count'  => $document->chunkCount,
			'status'       => $document->status,
			'updated_at'   => $this->now(),
		);

		if ( 'indexed' === $document->status ) {
			$data['indexed_at'] = $this->now();
		}

		if ( null === $document->id ) {
			$data['created_at'] = $this->now();

			$id = $this->insertRow( $data );

			if ( null !== $id ) {
				$document->id = $id;
			}

			return $document;
		}

		$this->updateRow( $document->id, $data );

		return $document;
	}

	/**
	 * Delete a document and everything derived from it.
	 *
	 * The dependants are removed explicitly because there is nothing to
	 * remove them automatically. The schema declares no foreign keys —
	 * dbDelta does not support them and hosts differ on whether they
	 * survive a migration — so "ON DELETE CASCADE" is not available and
	 * assuming it leaves orphaned chunks and embedding blobs behind. Those
	 * are invisible: nothing lists them, and they are the largest rows in
	 * the database.
	 *
	 * Children first. A failure partway then leaves a document with fewer
	 * chunks, which re-indexing repairs; the reverse order would leave
	 * chunks belonging to nothing, which nothing repairs.
	 *
	 * @param int $id Storage id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		$embeddings = Schema::table( Schema::EMBEDDINGS );
		$chunks     = Schema::table( Schema::CHUNKS );

		// Embeddings carry chunk_id but not document_id, so reaching them
		// from a document needs the join. They go first: retrieval reads
		// vectors before it reads chunk text, so a vector pointing at a
		// deleted chunk is the one ordering that can surface an error.
		$this->execute(
			"DELETE e FROM `{$embeddings}` e
			 INNER JOIN `{$chunks}` c ON c.id = e.chunk_id
			 WHERE c.document_id = %d",
			array( $id )
		);

		$this->execute( "DELETE FROM `{$chunks}` WHERE document_id = %d", array( $id ) );

		return $this->deleteRow( $id );
	}

	public function deleteForSource( int $sourceId ): int {
		$embeddings = Schema::table( Schema::EMBEDDINGS );
		$chunks     = Schema::table( Schema::CHUNKS );

		// Both tables denormalise source_id precisely so a source can be
		// removed without joining anything.
		$this->execute( "DELETE FROM `{$embeddings}` WHERE source_id = %d", array( $sourceId ) );
		$this->execute( "DELETE FROM `{$chunks}` WHERE source_id = %d", array( $sourceId ) );

		$table = $this->tableName();

		$sql = $this->db->prepare( "DELETE FROM `{$table}` WHERE source_id = %d", $sourceId );

		if ( ! is_string( $sql ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->db->query( $sql );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Build a Document from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return Document
	 */
	private function hydrate( array $row ): Document {
		return new Document(
			id: isset( $row['id'] ) ? (int) $row['id'] : null,
			sourceId: (int) ( $row['source_id'] ?? 0 ),
			externalId: (string) ( $row['external_id'] ?? '' ),
			url: (string) ( $row['url'] ?? '' ),
			title: (string) ( $row['title'] ?? '' ),
			content: (string) ( $row['content'] ?? '' ),
			contentHash: (string) ( $row['content_hash'] ?? '' ),
			language: isset( $row['language'] ) ? (string) $row['language'] : null,
			metadata: $this->json( $row['metadata'] ?? null ),
			tokenCount: (int) ( $row['token_count'] ?? 0 ),
			chunkCount: (int) ( $row['chunk_count'] ?? 0 ),
			status: (string) ( $row['status'] ?? 'pending' ),
		);
	}
}
