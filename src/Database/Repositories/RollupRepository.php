<?php
/**
 * Counts a day out of the live tables.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Analytics\DailyMetrics;
use Hiveclerk\Domain\Analytics\RollupSourceInterface;

/**
 * The one place in the product that aggregates the message table.
 *
 * Everything here runs inside a background job over a bounded set of
 * days, never inside a request. That is the whole arrangement: this class
 * is allowed to be expensive precisely because the dashboard never calls
 * it.
 *
 * ## Which day a conversation belongs to
 *
 * Its start day, for every metric it carries. A conversation opened at
 * 23:50 on the 30th and closed at 00:10 on the 1st counts entirely on the
 * 30th, as do its messages, its handoff and its ratings. The alternative
 * — filing each event on the day it happened — makes
 * `resolved_by_ai / conversations` a ratio of two different populations,
 * and the deflection rate is the number the customer judges the product
 * by.
 *
 * The cost of that choice is that a day's figures can still change after
 * the day ends, which is why the rollup job re-processes a trailing
 * window rather than sealing a day and moving on.
 */
final class RollupRepository extends AbstractRepository implements RollupSourceInterface {

	/**
	 * Flag the guardrails record when nothing retrieved was confident.
	 *
	 * Matched as a substring of the JSON array rather than with a JSON
	 * path predicate, because a path expression cannot use an index and
	 * this query is already narrowed to one day's conversations. The flag
	 * vocabulary is a closed set defined in GuardrailService, so there is
	 * no visitor-controlled text that could collide with it.
	 */
	private const LOW_CONFIDENCE = 'low_confidence';

	/**
	 * Table constant.
	 *
	 * Nominally conversations: this class reads five tables and writes
	 * none, and the base class wants one name for the helpers it offers.
	 * Every query below names its own tables explicitly.
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
	 * Count one UTC day.
	 *
	 * @param string $date           Y-m-d.
	 * @param int    $qualifiedScore Score at which a lead counts as qualified.
	 * @return array<int, DailyMetrics>
	 */
	public function metricsFor( string $date, int $qualifiedScore ): array {
		$from = $date . ' 00:00:00';
		$to   = $date . ' 23:59:59';

		// Counted once for the whole day and then handed to each row.
		// Running them per clerk would multiply three grouped queries by
		// the size of the roster for figures that are already grouped by
		// clerk.
		$leads     = $this->leadCounts( $from, $to );
		$qualified = $this->qualifiedCounts( $from, $to, $qualifiedScore );
		$usage     = $this->usageCounts( $from, $to );
		$agentIds  = $this->activeAgents( $from, $to, $leads, $qualified, $usage );

		// The site-wide row first, and counted rather than summed. Unique
		// visitors do not add up across clerks: one person who spoke to
		// two clerks is two per-clerk visitors and one site visitor, and
		// summing would report the wrong figure in the direction that
		// flatters us.
		$rows = array( $this->assemble( $date, null, $from, $to, $leads, $qualified, $usage ) );

		foreach ( $agentIds as $agentId ) {
			$rows[] = $this->assemble( $date, $agentId, $from, $to, $leads, $qualified, $usage );
		}

		return $rows;
	}

	/**
	 * The earliest day with any conversation on it.
	 *
	 * @return string|null
	 */
	public function earliestDay(): ?string {
		$conversations = Schema::table( Schema::CONVERSATIONS );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $this->db->get_var( "SELECT MIN(started_at) FROM `{$conversations}`" );

		return is_string( $value ) && '' !== $value ? substr( $value, 0, 10 ) : null;
	}

	/**
	 * Which clerks saw anything on this day.
	 *
	 * @param string                              $from      Day start.
	 * @param string                              $to        Day end.
	 * @param array<int, int>                     $leads     Lead counts by clerk.
	 * @param array<int, int>                     $qualified Qualified counts by clerk.
	 * @param array<int, array<string, float|int>> $usage    Usage by clerk.
	 * @return array<int, int>
	 */
	private function activeAgents(
		string $from,
		string $to,
		array $leads,
		array $qualified,
		array $usage
	): array {
		$conversations = Schema::table( Schema::CONVERSATIONS );

		$spoke = $this->keyed(
			"SELECT agent_id AS k, COUNT(*) AS v
				FROM `{$conversations}`
				WHERE started_at BETWEEN %s AND %s
				GROUP BY agent_id",
			array( $from, $to )
		);

		$ids = array_merge(
			array_keys( $spoke ),
			array_keys( $leads ),
			array_keys( $qualified ),
			array_keys( $usage )
		);

		// Zero is the site-wide bucket in the three maps above, not a
		// clerk. Letting it through would write a row for agent 0.
		return array_values(
			array_filter(
				array_unique( array_map( 'intval', $ids ) ),
				static fn ( int $id ): bool => $id > 0
			)
		);
	}

	/**
	 * Assemble one row.
	 *
	 * The conversation and message aggregates are queried per row rather
	 * than grouped, because `COUNT(DISTINCT visitor_id)` grouped by clerk
	 * cannot also produce the site-wide distinct count — and that is the
	 * figure the dashboard shows.
	 *
	 * @param string                              $date      Y-m-d.
	 * @param int|null                            $agentId   Clerk, or null for site-wide.
	 * @param string                              $from      Day start.
	 * @param string                              $to        Day end.
	 * @param array<int, int>                     $leads     Lead counts by clerk.
	 * @param array<int, int>                     $qualified Qualified counts by clerk.
	 * @param array<int, array<string, float|int>> $usage    Usage by clerk.
	 * @return DailyMetrics
	 */
	private function assemble(
		string $date,
		?int $agentId,
		string $from,
		string $to,
		array $leads,
		array $qualified,
		array $usage
	): DailyMetrics {
		$conversations = Schema::table( Schema::CONVERSATIONS );
		$messages      = Schema::table( Schema::MESSAGES );

		$scope  = null === $agentId ? '' : ' AND c.agent_id = %d';
		$params = array( $from, $to );

		if ( null !== $agentId ) {
			$params[] = $agentId;
		}

		$conversation = $this->aggregate(
			"SELECT COUNT(*) AS conversations,
					COUNT(DISTINCT c.visitor_id) AS unique_visitors,
					SUM(c.handoff_at IS NOT NULL) AS handoffs,
					SUM(c.resolved_by_ai = 1) AS resolved_by_ai
				FROM `{$conversations}` c
				WHERE c.started_at BETWEEN %s AND %s{$scope}",
			$params
		);

		$message = $this->aggregate(
			"SELECT COUNT(*) AS messages,
					SUM(m.rating = 1) AS positive_ratings,
					SUM(m.rating = -1) AS negative_ratings,
					SUM(m.guardrail_flags LIKE %s) AS unanswered,
					AVG(NULLIF(m.latency_ms, 0)) AS avg_latency_ms
				FROM `{$messages}` m
				INNER JOIN `{$conversations}` c ON c.id = m.conversation_id
				WHERE c.started_at BETWEEN %s AND %s{$scope}",
			array_merge( array( '%"' . self::LOW_CONFIDENCE . '"%' ), $params )
		);

		$key     = $agentId ?? 0;
		$latency = $message['avg_latency_ms'] ?? null;

		return new DailyMetrics(
			$date,
			$agentId,
			(int) ( $conversation['conversations'] ?? 0 ),
			(int) ( $message['messages'] ?? 0 ),
			(int) ( $conversation['unique_visitors'] ?? 0 ),
			(int) ( $leads[ $key ] ?? 0 ),
			(int) ( $qualified[ $key ] ?? 0 ),
			(int) ( $conversation['handoffs'] ?? 0 ),
			(int) ( $conversation['resolved_by_ai'] ?? 0 ),
			(int) ( $message['positive_ratings'] ?? 0 ),
			(int) ( $message['negative_ratings'] ?? 0 ),
			(int) ( $message['unanswered'] ?? 0 ),
			(int) ( $usage[ $key ]['tokens_in'] ?? 0 ),
			(int) ( $usage[ $key ]['tokens_out'] ?? 0 ),
			(float) ( $usage[ $key ]['cost'] ?? 0.0 ),
			null === $latency ? null : (int) round( (float) $latency ),
			(int) ( $usage[ $key ]['unpriced'] ?? 0 )
		);
	}

	/**
	 * Leads first captured in a window, keyed by clerk.
	 *
	 * The site-wide entry counts every lead created that day, including
	 * ones no conversation produced. A lead typed in by hand or imported
	 * is still a lead the site gained, and a total that silently omitted
	 * it would disagree with the pipeline board on the next screen along.
	 *
	 * @param string $from Window start.
	 * @param string $to   Window end.
	 * @return array<int, int> Keyed by clerk; 0 is the site-wide bucket.
	 */
	private function leadCounts( string $from, string $to ): array {
		$leads         = Schema::table( Schema::LEADS );
		$conversations = Schema::table( Schema::CONVERSATIONS );

		$counts = $this->keyed(
			"SELECT c.agent_id AS k, COUNT(DISTINCT l.id) AS v
				FROM `{$leads}` l
				INNER JOIN `{$conversations}` c ON c.lead_id = l.id
				WHERE l.created_at BETWEEN %s AND %s AND l.deleted_at IS NULL
				GROUP BY c.agent_id",
			array( $from, $to )
		);

		$counts[0] = $this->scalar(
			"SELECT COUNT(*) FROM `{$leads}`
				WHERE created_at BETWEEN %s AND %s AND deleted_at IS NULL",
			array( $from, $to )
		);

		return $counts;
	}

	/**
	 * Leads that crossed into the qualified band in a window.
	 *
	 * Read from the append-only score log rather than from `score_band` on
	 * the lead, because the band is current state: a lead that qualified
	 * in March and was disqualified in April carries the April value, and
	 * counting it there would move a number out of a month that has
	 * already been reported.
	 *
	 * The inner query has no index to use — `score_after` is not indexed,
	 * and adding one would slow every scoring write to speed up a query
	 * that runs hourly. The scan is the deliberate trade.
	 *
	 * @param string $from      Window start.
	 * @param string $to        Window end.
	 * @param int    $threshold Qualifying score.
	 * @return array<int, int> Keyed by clerk; 0 is the site-wide bucket.
	 */
	private function qualifiedCounts( string $from, string $to, int $threshold ): array {
		$scores        = Schema::table( Schema::LEAD_SCORES );
		$conversations = Schema::table( Schema::CONVERSATIONS );

		$counts = $this->keyed(
			"SELECT COALESCE(c.agent_id, 0) AS k, COUNT(*) AS v
				FROM (
					SELECT lead_id, MIN(id) AS event_id
					FROM `{$scores}`
					WHERE score_after >= %d
					GROUP BY lead_id
				) f
				INNER JOIN `{$scores}` s ON s.id = f.event_id
				LEFT JOIN `{$conversations}` c ON c.id = s.conversation_id
				WHERE s.created_at BETWEEN %s AND %s
				GROUP BY COALESCE(c.agent_id, 0)",
			array( $threshold, $from, $to )
		);

		// Summed rather than counted separately: unlike visitors, a lead
		// crosses the line exactly once, so every bucket is disjoint.
		$counts[0] = array_sum( $counts );

		return $counts;
	}

	/**
	 * Tokens and spend in a window, keyed by clerk.
	 *
	 * Unpriced calls contribute nothing to `cost` rather than a zero, in
	 * line with the rest of the product: `SUM()` skips NULL, which is the
	 * behaviour wanted and the reason the column is nullable.
	 *
	 * @param string $from Window start.
	 * @param string $to   Window end.
	 * @return array<int, array<string, float|int>> Keyed by clerk; 0 is site-wide.
	 */
	private function usageCounts( string $from, string $to ): array {
		$usage = Schema::table( Schema::USAGE_EVENTS );

		$rows = $this->rows(
			/*
			 * `SUM(cost)` skips NULLs, so an unpriced call contributes
			 * nothing and the total looks complete. That is the right sum —
			 * there is no figure to add — but on its own it turned the
			 * nullable column M0008 introduced back into a silent zero the
			 * moment the day was rolled up. The count travels with it.
			 */
			"SELECT COALESCE(agent_id, 0) AS k,
					COALESCE(SUM(tokens_in), 0) AS tokens_in,
					COALESCE(SUM(tokens_out), 0) AS tokens_out,
					COALESCE(SUM(cost), 0) AS cost,
					COALESCE(SUM(cost IS NULL), 0) AS unpriced
				FROM `{$usage}`
				WHERE occurred_at BETWEEN %s AND %s
				GROUP BY COALESCE(agent_id, 0)",
			array( $from, $to )
		);

		$byAgent = array();
		$total   = array(
			'tokens_in'  => 0,
			'tokens_out' => 0,
			'cost'       => 0.0,
			'unpriced'   => 0,
		);

		foreach ( $rows as $row ) {
			$slice = array(
				'tokens_in'  => (int) $row['tokens_in'],
				'tokens_out' => (int) $row['tokens_out'],
				'cost'       => (float) $row['cost'],
				'unpriced'   => (int) $row['unpriced'],
			);

			$byAgent[ (int) $row['k'] ] = $slice;

			$total['tokens_in']  += $slice['tokens_in'];
			$total['tokens_out'] += $slice['tokens_out'];
			$total['cost']       += $slice['cost'];
			$total['unpriced']   += $slice['unpriced'];
		}

		// Overwrites any bucket the query produced for unattributed calls,
		// which is intended: the site-wide row is every call, attributed
		// or not.
		$byAgent[0] = $total;

		return $byAgent;
	}

	/**
	 * Run an aggregate that returns one row.
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
	 * Run a two-column query into a map.
	 *
	 * @param string            $sql    Statement selecting `k` and `v`.
	 * @param array<int, mixed> $params Values.
	 * @return array<int, int>
	 */
	private function keyed( string $sql, array $params ): array {
		$map = array();

		foreach ( $this->rows( $sql, $params ) as $row ) {
			$map[ (int) $row['k'] ] = (int) $row['v'];
		}

		return $map;
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
