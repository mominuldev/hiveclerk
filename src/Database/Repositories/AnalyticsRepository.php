<?php
/**
 * Rollup persistence.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Analytics\AnalyticsRepositoryInterface;
use Hiveclerk\Domain\Analytics\DailyMetrics;
use Hiveclerk\Domain\Shared\DateRange;

/**
 * Reads and writes `hvc_analytics_daily`.
 *
 * ## Why `put()` is a lookup and then a write, not an upsert
 *
 * `uq_date_agent (date, agent_id)` looks like it makes
 * `INSERT … ON DUPLICATE KEY UPDATE` safe, and for a per-clerk row it
 * does. It does not for the site-wide row, because `agent_id` is NULL
 * there and MySQL treats every NULL in a unique index as distinct from
 * every other. An upsert would insert a second site-wide row for the same
 * day on every rollup, and the dashboard — which reads them all and adds
 * them up — would report double the conversations after the second run,
 * triple after the third. Nothing would error.
 *
 * Reading the row first costs one indexed lookup per day per clerk on a
 * job that runs hourly over a handful of days. That is a price worth
 * paying for a figure the customer is going to quote to their boss.
 */
final class AnalyticsRepository extends AbstractRepository implements AnalyticsRepositoryInterface {

	/**
	 * Table constant.
	 *
	 * @return string
	 */
	protected function table(): string {
		return Schema::ANALYTICS_DAILY;
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<int, string>
	 */
	protected function sortableColumns(): array {
		return array( 'date', 'conversations', 'cost' );
	}

	/**
	 * Write one day, replacing whatever was there.
	 *
	 * @param DailyMetrics $metrics One day.
	 * @return void
	 */
	public function put( DailyMetrics $metrics ): void {
		$data = array(
			'date'             => $metrics->date,
			'agent_id'         => $metrics->agentId,
			'conversations'    => $metrics->conversations,
			'messages'         => $metrics->messages,
			'unique_visitors'  => $metrics->uniqueVisitors,
			'leads_captured'   => $metrics->leadsCaptured,
			'leads_qualified'  => $metrics->leadsQualified,
			'handoffs'         => $metrics->handoffs,
			'resolved_by_ai'   => $metrics->resolvedByAi,
			'positive_ratings' => $metrics->positiveRatings,
			'negative_ratings' => $metrics->negativeRatings,
			'unanswered'       => $metrics->unanswered,
			'tokens_in'        => $metrics->tokensIn,
			'tokens_out'       => $metrics->tokensOut,
			'cost'             => $metrics->cost,
			'unpriced'         => $metrics->unpriced,
			'avg_latency_ms'   => $metrics->avgLatencyMs,
		);

		$existing = $this->idFor( $metrics->date, $metrics->agentId );

		if ( null === $existing ) {
			$this->insertRow( $data );

			return;
		}

		$this->updateRow( $existing, $data );
	}

	/**
	 * Stored rows over a range, oldest first.
	 *
	 * @param DateRange $range   Span.
	 * @param int|null  $agentId Clerk, or null for site-wide.
	 * @return array<int, DailyMetrics>
	 */
	public function between( DateRange $range, ?int $agentId = null ): array {
		$where  = 'date BETWEEN %s AND %s AND ' . $this->agentPredicate( $agentId );
		$params = array( $range->from, $range->to );

		if ( null !== $agentId ) {
			$params[] = $agentId;
		}

		return array_map(
			array( self::class, 'hydrate' ),
			$this->fetchAll( $where, $params, 'date', 'ASC' )
		);
	}

	/**
	 * Stored rows for every clerk over a range.
	 *
	 * @param DateRange $range Span.
	 * @return array<int, array<int, DailyMetrics>>
	 */
	public function byAgent( DateRange $range ): array {
		$rows = $this->fetchAll(
			'date BETWEEN %s AND %s AND agent_id IS NOT NULL',
			array( $range->from, $range->to ),
			'date',
			'ASC'
		);

		$grouped = array();

		foreach ( $rows as $row ) {
			$agentId = (int) $row['agent_id'];

			$grouped[ $agentId ][] = self::hydrate( $row );
		}

		return $grouped;
	}

	/**
	 * The most recent day that has been rolled up.
	 *
	 * @return string|null
	 */
	public function lastRolledUp(): ?string {
		$table = $this->tableName();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $this->db->get_var( "SELECT MAX(`date`) FROM `{$table}`" );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Delete rows older than a cut-off.
	 *
	 * @param string $before Exclusive Y-m-d.
	 * @return int
	 */
	public function purgeBefore( string $before ): int {
		$table = $this->tableName();

		if ( ! $this->execute( "DELETE FROM `{$table}` WHERE `date` < %s", array( $before ) ) ) {
			return 0;
		}

		return (int) $this->db->rows_affected;
	}

	/**
	 * Row id for a day and clerk, honouring the NULL site-wide case.
	 *
	 * @param string   $date    Y-m-d.
	 * @param int|null $agentId Clerk.
	 * @return int|null
	 */
	private function idFor( string $date, ?int $agentId ): ?int {
		$params = array( $date );

		if ( null !== $agentId ) {
			$params[] = $agentId;
		}

		$row = $this->fetchRow( 'date = %s AND ' . $this->agentPredicate( $agentId ), $params );

		return null === $row ? null : (int) $row['id'];
	}

	/**
	 * The clerk half of a WHERE clause.
	 *
	 * `= NULL` matches nothing in SQL, so the site-wide row needs
	 * `IS NULL` — the same trap that rules out an upsert above, in the
	 * read direction where it would silently return no rows instead of
	 * silently writing extra ones.
	 *
	 * @param int|null $agentId Clerk.
	 * @return string
	 */
	private function agentPredicate( ?int $agentId ): string {
		return null === $agentId ? 'agent_id IS NULL' : 'agent_id = %d';
	}

	/**
	 * Build a DailyMetrics from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return DailyMetrics
	 */
	private static function hydrate( array $row ): DailyMetrics {
		return new DailyMetrics(
			(string) $row['date'],
			null === $row['agent_id'] ? null : (int) $row['agent_id'],
			(int) $row['conversations'],
			(int) $row['messages'],
			(int) $row['unique_visitors'],
			(int) $row['leads_captured'],
			(int) $row['leads_qualified'],
			(int) $row['handoffs'],
			(int) $row['resolved_by_ai'],
			(int) $row['positive_ratings'],
			(int) $row['negative_ratings'],
			(int) $row['unanswered'],
			(int) $row['tokens_in'],
			(int) $row['tokens_out'],
			(float) $row['cost'],
			null === $row['avg_latency_ms'] ? null : (int) $row['avg_latency_ms'],
			(int) ( $row['unpriced'] ?? 0 )
		);
	}
}
