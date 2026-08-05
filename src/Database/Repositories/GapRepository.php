<?php
/**
 * Knowledge-gap persistence.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Analytics\GapRepositoryInterface;
use Hiveclerk\Domain\Analytics\GapStatus;
use Hiveclerk\Domain\Analytics\KnowledgeGap;
use Hiveclerk\Domain\Shared\DateRange;
use Hiveclerk\Domain\Shared\Pagination;

/**
 * Reads and writes `hvc_unanswered`.
 *
 * The interesting method is `record()`, which is called from the chat
 * path on a request a visitor is waiting on. It does one indexed lookup
 * and one write, and it takes the row's existing status as authoritative
 * — see the interface for why an operator's decision outranks a fresh
 * sighting.
 */
final class GapRepository extends AbstractRepository implements GapRepositoryInterface {

	/**
	 * Table constant.
	 *
	 * @return string
	 */
	protected function table(): string {
		return Schema::UNANSWERED;
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<int, string>
	 */
	protected function sortableColumns(): array {
		return array( 'occurrences', 'last_seen_at', 'first_seen_at', 'best_score' );
	}

	/**
	 * Record a gap, or count another occurrence of a known one.
	 *
	 * @param KnowledgeGap $gap Gap to record.
	 * @return KnowledgeGap
	 */
	public function record( KnowledgeGap $gap ): KnowledgeGap {
		$now      = $this->now();
		$existing = $this->fetchRow(
			'agent_id = %d AND query_hash = %s',
			array( $gap->agentId, $gap->queryHash )
		);

		if ( null === $existing ) {
			$id = $this->insertRow(
				array(
					'agent_id'        => $gap->agentId,
					'conversation_id' => $gap->conversationId,
					'query'           => $this->clip( $gap->query ),
					'query_hash'      => $gap->queryHash,
					'best_score'      => $gap->bestScore,
					'occurrences'     => 1,
					'status'          => GapStatus::Open->value,
					'first_seen_at'   => $now,
					'last_seen_at'    => $now,
				)
			);

			return null === $id ? $gap : ( $this->find( $id ) ?? $gap );
		}

		$table = $this->tableName();
		$id    = (int) $existing['id'];

		/*
		 * The best score is kept as the best ever seen, not the latest. A
		 * question that scored 0.58 last week and 0.11 today has not got
		 * worse — the second visitor phrased it differently — and showing
		 * the weaker number would send the operator to write content that
		 * already exists.
		 *
		 * Incremented in SQL rather than read-modify-written in PHP: two
		 * visitors asking the same question in the same second would
		 * otherwise both read 4 and both write 5.
		 */
		// The conversation is only overwritten when this sighting has one.
		// A gap re-recorded from a context without a conversation would
		// otherwise lose the link to the transcript that produced it, and
		// "where was this asked" is the first thing an operator clicks.
		$conversation = null === $gap->conversationId
			? ''
			: ', conversation_id = ' . (int) $gap->conversationId;

		$this->execute(
			"UPDATE `{$table}`
				SET occurrences = occurrences + 1,
					last_seen_at = %s,
					best_score = GREATEST(COALESCE(best_score, 0), %f){$conversation}
				WHERE id = %d",
			array( $now, (float) $gap->bestScore, $id )
		);

		return $this->find( $id ) ?? $gap;
	}

	/**
	 * One gap by id.
	 *
	 * @param int $id Row id.
	 * @return KnowledgeGap|null
	 */
	public function find( int $id ): ?KnowledgeGap {
		$row = $this->fetchRow( 'id = %d', array( $id ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * A page of gaps.
	 *
	 * @param GapStatus|null $status  Restrict to one status.
	 * @param int|null       $agentId Restrict to one clerk.
	 * @param Pagination     $page    Page window.
	 * @return array<int, KnowledgeGap>
	 */
	public function paginate( ?GapStatus $status, ?int $agentId, Pagination $page ): array {
		[ $where, $params ] = $this->filter( $status, $agentId );

		return array_map(
			array( $this, 'hydrate' ),
			$this->fetchAll(
				$where,
				$params,
				'occurrences',
				'DESC',
				$page->perPage,
				$page->offset()
			)
		);
	}

	/**
	 * How many gaps match a filter.
	 *
	 * @param GapStatus|null $status  Restrict to one status.
	 * @param int|null       $agentId Restrict to one clerk.
	 * @return int
	 */
	public function count( ?GapStatus $status, ?int $agentId = null ): int {
		[ $where, $params ] = $this->filter( $status, $agentId );

		return $this->countWhere( $where, $params );
	}

	/**
	 * Move a gap to a new status.
	 *
	 * @param int       $id         Row id.
	 * @param GapStatus $status     New status.
	 * @param int|null  $resolvedBy WordPress user who acted.
	 * @return bool
	 */
	public function setStatus( int $id, GapStatus $status, ?int $resolvedBy = null ): bool {
		return $this->updateRow(
			$id,
			array(
				'status'      => $status->value,
				'resolved_by' => $status->isOpen() ? null : $resolvedBy,
			)
		);
	}

	/**
	 * Occurrences recorded per day over a range.
	 *
	 * @param DateRange $range Span.
	 * @return array<string, int>
	 */
	public function dailyCounts( DateRange $range ): array {
		$table = $this->tableName();

		$prepared = $this->db->prepare(
			"SELECT DATE(last_seen_at) AS d, COUNT(*) AS n
				FROM `{$table}`
				WHERE last_seen_at BETWEEN %s AND %s
				GROUP BY DATE(last_seen_at)",
			$range->startsAt(),
			$range->endsAt()
		);

		if ( ! is_string( $prepared ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $prepared, ARRAY_A );

		$counts = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['d'] ] = (int) $row['n'];
		}

		return $counts;
	}

	/**
	 * Open gaps last seen within a range, most asked first.
	 *
	 * @param DateRange $range Span.
	 * @param int       $limit How many.
	 * @return array<int, KnowledgeGap>
	 */
	public function topOpen( DateRange $range, int $limit = 5 ): array {
		return array_map(
			array( $this, 'hydrate' ),
			$this->fetchAll(
				'status = %s AND last_seen_at BETWEEN %s AND %s',
				array( GapStatus::Open->value, $range->startsAt(), $range->endsAt() ),
				'occurrences',
				'DESC',
				max( 1, min( 50, $limit ) )
			)
		);
	}

	/**
	 * Build the shared WHERE clause.
	 *
	 * @param GapStatus|null $status  Status filter.
	 * @param int|null       $agentId Clerk filter.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function filter( ?GapStatus $status, ?int $agentId ): array {
		$where  = '1=1';
		$params = array();

		if ( null !== $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status->value;
		}

		if ( null !== $agentId ) {
			$where   .= ' AND agent_id = %d';
			$params[] = $agentId;
		}

		return array( $where, $params );
	}

	/**
	 * Trim a question to what the column holds.
	 *
	 * Truncated on the way in rather than left to MySQL, which either
	 * truncates silently or refuses the whole insert depending on the
	 * server's strict mode — and losing the gap entirely because somebody
	 * pasted an essay is the worse of the two.
	 *
	 * @param string $query Raw question.
	 * @return string
	 */
	private function clip( string $query ): string {
		return function_exists( 'mb_substr' )
			? mb_substr( $query, 0, 500 )
			: substr( $query, 0, 500 );
	}

	/**
	 * Build a gap from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return KnowledgeGap
	 */
	private function hydrate( array $row ): KnowledgeGap {
		return new KnowledgeGap(
			(int) $row['id'],
			(int) $row['agent_id'],
			(string) $row['query'],
			(string) $row['query_hash'],
			null === $row['best_score'] ? null : (float) $row['best_score'],
			(int) $row['occurrences'],
			GapStatus::fromStorage( is_string( $row['status'] ) ? $row['status'] : null ),
			$this->intOrNull( $row['conversation_id'] ),
			$this->intOrNull( $row['resolved_by'] ),
			$this->time( $row['first_seen_at'] ),
			$this->time( $row['last_seen_at'] )
		);
	}
}
