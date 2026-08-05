<?php
/**
 * Score event repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Lead\ScoreEvent;
use Hiveclerk\Domain\Lead\ScoreEventRepositoryInterface;
use Hiveclerk\Domain\Lead\ScoreSource;

/**
 * Stores the append-only score log (D7 §5.2).
 *
 * There is no update method here and its absence is load-bearing. The
 * lead's `score` column is a cache of this table's SUM; if a row could
 * be edited after the fact, the two would disagree and the breakdown a
 * salesperson reads would stop adding up to the number above it.
 */
final class ScoreEventRepository extends AbstractRepository implements ScoreEventRepositoryInterface {

	protected function table(): string {
		return Schema::LEAD_SCORES;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'created_at' );
	}

	public function append( ScoreEvent $event ): ScoreEvent {
		$created = $event->createdAt ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$id = $this->insertRow(
			array(
				'lead_id'         => $event->leadId,
				'conversation_id' => $event->conversationId,
				'rule_id'         => $event->ruleId,
				'rule_label'      => $event->ruleLabel,
				'source'          => $event->source->value,
				'points'          => $event->points,
				'score_after'     => $event->scoreAfter,
				'rationale'       => $event->rationale,
				'created_at'      => $created->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			)
		);

		$event->createdAt = $created;

		if ( null !== $id ) {
			$event->id = $id;
		}

		return $event;
	}

	public function forLead( int $leadId, int $limit = 200 ): array {
		$rows = $this->fetchAll( 'lead_id = %d', array( $leadId ), 'created_at', 'ASC', $limit );

		return array_map( fn ( array $row ): ScoreEvent => $this->hydrate( $row ), $rows );
	}

	public function awardedRuleIds( int $leadId ): array {
		$table = $this->tableName();

		$prepared = $this->db->prepare(
			"SELECT DISTINCT rule_id FROM `{$table}` WHERE lead_id = %d AND rule_id IS NOT NULL",
			$leadId
		);

		if ( ! is_string( $prepared ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $this->db->get_col( $prepared );

		return is_array( $ids ) ? array_values( array_map( 'strval', $ids ) ) : array();
	}

	public function total( int $leadId ): int {
		$table = $this->tableName();

		$prepared = $this->db->prepare(
			"SELECT COALESCE(SUM(points), 0) FROM `{$table}` WHERE lead_id = %d",
			$leadId
		);

		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->get_var( $prepared );
	}

	public function reassign( int $from, int $to ): int {
		$table = $this->tableName();

		$done = $this->execute(
			"UPDATE `{$table}` SET lead_id = %d WHERE lead_id = %d",
			array( $to, $from )
		);

		return $done ? (int) $this->db->rows_affected : 0;
	}

	public function deleteForLead( int $leadId ): int {
		$table = $this->tableName();

		$done = $this->execute( "DELETE FROM `{$table}` WHERE lead_id = %d", array( $leadId ) );

		return $done ? (int) $this->db->rows_affected : 0;
	}

	/**
	 * Build a ScoreEvent from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return ScoreEvent
	 */
	private function hydrate( array $row ): ScoreEvent {
		$created = $row['created_at'] ?? null;

		return new ScoreEvent(
			id: (int) $row['id'],
			leadId: (int) $row['lead_id'],
			conversationId: $this->intOrNull( $row['conversation_id'] ?? null ),
			ruleId: $this->text( $row['rule_id'] ?? null ),
			ruleLabel: $this->text( $row['rule_label'] ?? null ),
			source: ScoreSource::fromStorage( $this->text( $row['source'] ?? null ) ),
			points: (int) ( $row['points'] ?? 0 ),
			scoreAfter: (int) ( $row['score_after'] ?? 0 ),
			rationale: $this->text( $row['rationale'] ?? null ),
			createdAt: is_string( $created ) && '' !== $created
				? new DateTimeImmutable( $created, new DateTimeZone( 'UTC' ) )
				: null,
		);
	}
}
