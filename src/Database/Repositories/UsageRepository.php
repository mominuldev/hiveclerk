<?php
/**
 * Usage event persistence.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Usage\UsageEvent;
use Hiveclerk\Domain\Usage\UsageRepositoryInterface;
use Hiveclerk\Domain\Usage\UsageSummary;

/**
 * Writes metered calls and aggregates them for the cost screens.
 *
 * Aggregation happens in SQL rather than PHP. A busy site records tens of
 * thousands of these a month and the dashboard must not load them into
 * memory to add them up — that is exactly the query that works fine for
 * the developer and takes the site down for the customer.
 */
final class UsageRepository extends AbstractRepository implements UsageRepositoryInterface {

	/**
	 * Table constant.
	 *
	 * @return string
	 */
	protected function table(): string {
		return Schema::USAGE_EVENTS;
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<int, string>
	 */
	protected function sortableColumns(): array {
		return array( 'occurred_at', 'cost', 'tokens_in', 'tokens_out' );
	}

	/**
	 * Record one call.
	 *
	 * @param UsageEvent $event Event.
	 * @return void
	 */
	public function record( UsageEvent $event ): void {
		$this->insertRow(
			array(
				'agent_id'        => $event->agentId,
				'conversation_id' => $event->conversationId,
				'kind'            => $event->kind->value,
				'provider'        => $event->provider,
				'model'           => $event->model,
				'tokens_in'       => $event->tokensIn,
				'tokens_out'      => $event->tokensOut,
				'cost'            => $event->cost,
				'latency_ms'      => $event->latencyMs,
				'occurred_at'     => $this->now(),
			)
		);
	}

	/**
	 * Totals over a date range.
	 *
	 * @param string   $from    Inclusive UTC date.
	 * @param string   $to      Inclusive UTC date.
	 * @param int|null $agentId Restrict to one clerk.
	 * @return UsageSummary
	 */
	public function summarise( string $from, string $to, ?int $agentId = null ): UsageSummary {
		$table  = $this->tableName();
		$params = array( $from . ' 00:00:00', $to . ' 23:59:59' );
		$where  = 'occurred_at BETWEEN %s AND %s';

		if ( null !== $agentId ) {
			$where   .= ' AND agent_id = %d';
			$params[] = $agentId;
		}

		$sql = "SELECT COUNT(*) AS calls,
					COALESCE(SUM(tokens_in), 0) AS tokens_in,
					COALESCE(SUM(tokens_out), 0) AS tokens_out,
					COALESCE(SUM(cost), 0) AS cost,
					SUM(cost IS NULL) AS unpriced
				FROM `{$table}` WHERE {$where}";

		$row = $this->fetchAggregate( $sql, $params );

		return self::toSummary( 'all', $row );
	}

	/**
	 * Totals per provider and model.
	 *
	 * @param string $from Inclusive UTC date.
	 * @param string $to   Inclusive UTC date.
	 * @return array<int, UsageSummary>
	 */
	public function byModel( string $from, string $to ): array {
		$table = $this->tableName();

		$sql = "SELECT provider, model AS label,
					COUNT(*) AS calls,
					COALESCE(SUM(tokens_in), 0) AS tokens_in,
					COALESCE(SUM(tokens_out), 0) AS tokens_out,
					COALESCE(SUM(cost), 0) AS cost,
					SUM(cost IS NULL) AS unpriced
				FROM `{$table}`
				WHERE occurred_at BETWEEN %s AND %s
				GROUP BY provider, model
				ORDER BY cost DESC
				LIMIT 50";

		return $this->fetchSummaries( $sql, array( $from . ' 00:00:00', $to . ' 23:59:59' ) );
	}

	/**
	 * Daily totals, oldest first.
	 *
	 * Gaps are not filled here. A day with no calls has no row, and the
	 * chart component decides whether that reads better as a zero or as a
	 * break in the line — a decision that belongs to the presentation,
	 * not to the query.
	 *
	 * @param string $from Inclusive UTC date.
	 * @param string $to   Inclusive UTC date.
	 * @return array<int, UsageSummary>
	 */
	public function daily( string $from, string $to ): array {
		$table = $this->tableName();

		$sql = "SELECT DATE(occurred_at) AS label,
					COUNT(*) AS calls,
					COALESCE(SUM(tokens_in), 0) AS tokens_in,
					COALESCE(SUM(tokens_out), 0) AS tokens_out,
					COALESCE(SUM(cost), 0) AS cost,
					SUM(cost IS NULL) AS unpriced
				FROM `{$table}`
				WHERE occurred_at BETWEEN %s AND %s
				GROUP BY DATE(occurred_at)
				ORDER BY label ASC";

		return $this->fetchSummaries( $sql, array( $from . ' 00:00:00', $to . ' 23:59:59' ) );
	}

	/**
	 * Delete events older than a cut-off.
	 *
	 * @param string $before Exclusive UTC date.
	 * @return int
	 */
	public function purgeBefore( string $before ): int {
		$table = $this->tableName();

		$this->execute(
			"DELETE FROM `{$table}` WHERE occurred_at < %s",
			array( $before . ' 00:00:00' )
		);

		return (int) $this->db->rows_affected;
	}

	/**
	 * Run an aggregate query returning one row.
	 *
	 * @param string            $sql    Statement with placeholders.
	 * @param array<int, mixed> $params Values.
	 * @return array<string, mixed>
	 */
	private function fetchAggregate( string $sql, array $params ): array {
		$prepared = $this->db->prepare( $sql, ...$params );

		if ( ! is_string( $prepared ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->db->get_row( $prepared, ARRAY_A );

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Run an aggregate query returning many rows.
	 *
	 * @param string            $sql    Statement with placeholders.
	 * @param array<int, mixed> $params Values.
	 * @return array<int, UsageSummary>
	 */
	private function fetchSummaries( string $sql, array $params ): array {
		$prepared = $this->db->prepare( $sql, ...$params );

		if ( ! is_string( $prepared ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $prepared, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$summaries = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$summaries[] = self::toSummary( self::string( $row, 'label' ), $row );
			}
		}

		return $summaries;
	}

	/**
	 * Build a summary from an aggregate row.
	 *
	 * @param string               $label Slice label.
	 * @param array<string, mixed> $row   Aggregate row.
	 * @return UsageSummary
	 */
	private static function toSummary( string $label, array $row ): UsageSummary {
		return new UsageSummary(
			label: $label,
			calls: self::int( $row, 'calls' ),
			tokensIn: self::int( $row, 'tokens_in' ),
			tokensOut: self::int( $row, 'tokens_out' ),
			cost: self::float( $row, 'cost' ),
			unpriced: self::int( $row, 'unpriced' ),
			provider: self::string( $row, 'provider' )
		);
	}

	/**
	 * Read an integer column.
	 *
	 * @param array<string, mixed> $row Row.
	 * @param string               $key Column.
	 * @return int
	 */
	private static function int( array $row, string $key ): int {
		$value = $row[ $key ] ?? 0;

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Read a float column.
	 *
	 * @param array<string, mixed> $row Row.
	 * @param string               $key Column.
	 * @return float
	 */
	private static function float( array $row, string $key ): float {
		$value = $row[ $key ] ?? 0;

		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Read a string column.
	 *
	 * @param array<string, mixed> $row Row.
	 * @param string               $key Column.
	 * @return string
	 */
	private static function string( array $row, string $key ): string {
		$value = $row[ $key ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}
}
