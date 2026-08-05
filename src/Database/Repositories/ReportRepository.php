<?php
/**
 * Funnel and topic reads.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Analytics\ReportSourceInterface;
use Hiveclerk\Domain\Shared\DateRange;

/**
 * The two reports that read live tables, kept away from the ones that
 * do not.
 *
 * Both are bounded by the range the operator chose, and the range itself
 * is bounded to a year by {@see DateRange}. That is the whole safety
 * argument: there is no request shape here that scans more than a year of
 * one site's conversations.
 */
final class ReportRepository extends AbstractRepository implements ReportSourceInterface {

	/**
	 * Messages a conversation needs before it counts as engaged.
	 *
	 * Three, from D11 §10. Two is a question and an answer, which every
	 * conversation has; three means the visitor came back after reading
	 * the reply, and that is the first moment there is any evidence of
	 * interest.
	 */
	private const ENGAGED_MESSAGES = 3;

	/**
	 * Table constant.
	 *
	 * @return string
	 */
	protected function table(): string {
		return Schema::CONVERSATIONS;
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<int, string>
	 */
	protected function sortableColumns(): array {
		return array();
	}

	/**
	 * The five funnel populations over a range.
	 *
	 * @param DateRange $range          Span.
	 * @param int       $qualifiedScore Qualifying score.
	 * @param int|null  $agentId        Restrict to one clerk.
	 * @return array{conversations: int, engaged: int, captured: int, qualified: int, won: int}
	 */
	public function funnel( DateRange $range, int $qualifiedScore, ?int $agentId = null ): array {
		$conversations = Schema::table( Schema::CONVERSATIONS );
		$leads         = Schema::table( Schema::LEADS );
		$scores        = Schema::table( Schema::LEAD_SCORES );
		$stages        = Schema::table( Schema::LEAD_STAGES );

		$scope  = null === $agentId ? '' : ' AND c.agent_id = %d';
		$window = array( $range->startsAt(), $range->endsAt() );
		$params = null === $agentId ? $window : array_merge( $window, array( $agentId ) );

		$top = $this->aggregate(
			"SELECT COUNT(*) AS conversations,
					SUM(c.message_count >= %d) AS engaged,
					COUNT(DISTINCT c.lead_id) AS captured
				FROM `{$conversations}` c
				WHERE c.started_at BETWEEN %s AND %s{$scope}",
			array_merge( array( self::ENGAGED_MESSAGES ), $params )
		);

		/*
		 * Qualified and won are counted from the leads the conversations
		 * in this range produced, not from every lead that qualified in
		 * it. Otherwise a lead captured in March and qualified in April
		 * appears in April's funnel under a conversation count that never
		 * included them, and the conversion rate exceeds 100%.
		 */
		$qualified = $this->scalar(
			"SELECT COUNT(DISTINCT c.lead_id)
				FROM `{$conversations}` c
				INNER JOIN `{$scores}` s ON s.lead_id = c.lead_id
				WHERE c.lead_id IS NOT NULL
					AND s.score_after >= %d
					AND c.started_at BETWEEN %s AND %s{$scope}",
			array_merge( array( $qualifiedScore ), $params )
		);

		$won = $this->scalar(
			"SELECT COUNT(DISTINCT c.lead_id)
				FROM `{$conversations}` c
				INNER JOIN `{$leads}` l ON l.id = c.lead_id
				INNER JOIN `{$stages}` st ON st.id = l.stage_id
				WHERE st.is_won = 1
					AND l.deleted_at IS NULL
					AND c.started_at BETWEEN %s AND %s{$scope}",
			$params
		);

		return array(
			'conversations' => (int) ( $top['conversations'] ?? 0 ),
			'engaged'       => (int) ( $top['engaged'] ?? 0 ),
			'captured'      => (int) ( $top['captured'] ?? 0 ),
			'qualified'     => $qualified,
			'won'           => $won,
		);
	}

	/**
	 * The opening question of each conversation in a range.
	 *
	 * @param DateRange $range   Span.
	 * @param int       $limit   Most conversations to read.
	 * @param int|null  $agentId Restrict to one clerk.
	 * @return array<int, string>
	 */
	public function openingQuestions( DateRange $range, int $limit, ?int $agentId = null ): array {
		$conversations = Schema::table( Schema::CONVERSATIONS );
		$messages      = Schema::table( Schema::MESSAGES );

		$scope  = null === $agentId ? '' : ' AND c.agent_id = %d';
		$window = array( $range->startsAt(), $range->endsAt() );
		$params = null === $agentId ? $window : array_merge( $window, array( $agentId ) );

		/*
		 * MIN(m.id) rather than MIN(m.created_at): two messages stored in
		 * the same second are ordered by insertion, and DATETIME has no
		 * sub-second precision to break the tie with. The id is the only
		 * total order the table has.
		 */
		$rows = $this->rows(
			"SELECT SUBSTRING(m.content, 1, 300) AS q
				FROM `{$conversations}` c
				INNER JOIN `{$messages}` m ON m.id = (
					SELECT MIN(id) FROM `{$messages}`
					WHERE conversation_id = c.id AND role = 'visitor'
				)
				WHERE c.started_at BETWEEN %s AND %s{$scope}
				ORDER BY c.started_at DESC
				LIMIT %d",
			array_merge( $params, array( max( 1, $limit ) ) )
		);

		return array_map(
			static fn ( array $row ): string => (string) $row['q'],
			$rows
		);
	}

	/**
	 * How many conversations the range holds.
	 *
	 * @param DateRange $range   Span.
	 * @param int|null  $agentId Restrict to one clerk.
	 * @return int
	 */
	public function conversationCount( DateRange $range, ?int $agentId = null ): int {
		$where  = 'started_at BETWEEN %s AND %s';
		$params = array( $range->startsAt(), $range->endsAt() );

		if ( null !== $agentId ) {
			$where   .= ' AND agent_id = %d';
			$params[] = $agentId;
		}

		return $this->countWhere( $where, $params );
	}

	/**
	 * Run an aggregate returning one row.
	 *
	 * @param string            $sql    Statement with placeholders.
	 * @param array<int, mixed> $params Values.
	 * @return array<string, mixed>
	 */
	private function aggregate( string $sql, array $params ): array {
		$prepared = $this->db->prepare( $sql, ...$params );

		if ( ! is_string( $prepared ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->db->get_row( $prepared, ARRAY_A );

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Run a query returning rows.
	 *
	 * @param string            $sql    Statement with placeholders.
	 * @param array<int, mixed> $params Values.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( string $sql, array $params ): array {
		$prepared = $this->db->prepare( $sql, ...$params );

		if ( ! is_string( $prepared ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $prepared, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Run a query returning one number.
	 *
	 * @param string            $sql    Statement with placeholders.
	 * @param array<int, mixed> $params Values.
	 * @return int
	 */
	private function scalar( string $sql, array $params ): int {
		$prepared = $this->db->prepare( $sql, ...$params );

		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->get_var( $prepared );
	}
}
