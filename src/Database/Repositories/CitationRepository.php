<?php
/**
 * Citation repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Conversation\Citation;
use Hiveclerk\Domain\Conversation\CitationRepositoryInterface;

/**
 * Stores message citations.
 */
final class CitationRepository extends AbstractRepository implements CitationRepositoryInterface {

	protected function table(): string {
		return Schema::MESSAGE_CITATIONS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'rank_order', 'score' );
	}

	public function saveFor( int $messageId, array $citations ): void {
		foreach ( $citations as $citation ) {
			$this->insertRow(
				array(
					'message_id'  => $messageId,
					'chunk_id'    => $citation->chunkId,
					'document_id' => $citation->documentId,
					// Clamped to the column. DECIMAL(5,4) holds -9.9999 to
					// 9.9999, and a cosine is already inside that, but a
					// keyword-only result carries an unbounded BM25 figure and
					// MySQL truncates an overflow to the maximum in a way that
					// makes a weak match look like a perfect one.
					'score'       => max( -0.9999, min( 0.9999, $citation->score ) ),
					'rank_order'  => max( 0, min( 255, $citation->rank ) ),
					'snapshot'    => $this->encodeJson( $citation->snapshot() ),
				)
			);
		}
	}

	public function forMessages( array $messageIds ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $messageIds ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$table        = $this->tableName();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$prepared = $this->db->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders only; values are bound below.
			"SELECT * FROM `{$table}` WHERE message_id IN ({$placeholders}) ORDER BY message_id ASC, rank_order ASC",
			...$ids
		);

		if ( ! is_string( $prepared ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $prepared, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$grouped = array();

		foreach ( $rows as $row ) {
			$messageId = (int) ( $row['message_id'] ?? 0 );

			$grouped[ $messageId ][] = $this->hydrate( $row );
		}

		return $grouped;
	}

	/**
	 * Build a Citation from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return Citation
	 */
	private function hydrate( array $row ): Citation {
		$snapshot = $this->json( $row['snapshot'] ?? null );

		return new Citation(
			id: isset( $row['id'] ) ? (int) $row['id'] : null,
			messageId: isset( $row['message_id'] ) ? (int) $row['message_id'] : null,
			chunkId: isset( $row['chunk_id'] ) ? (int) $row['chunk_id'] : null,
			documentId: isset( $row['document_id'] ) ? (int) $row['document_id'] : null,
			score: (float) ( $row['score'] ?? 0 ),
			rank: (int) ( $row['rank_order'] ?? 1 ),
			title: is_string( $snapshot['title'] ?? null ) ? $snapshot['title'] : '',
			url: is_string( $snapshot['url'] ?? null ) ? $snapshot['url'] : null,
			headingPath: is_string( $snapshot['heading_path'] ?? null ) ? $snapshot['heading_path'] : null,
			excerpt: is_string( $snapshot['excerpt'] ?? null ) ? $snapshot['excerpt'] : '',
		);
	}
}
