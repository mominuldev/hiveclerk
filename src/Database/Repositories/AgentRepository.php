<?php
/**
 * Agent repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Agent\AgentStatus;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Stores clerks.
 */
final class AgentRepository extends AbstractRepository implements AgentRepositoryInterface {

	protected function table(): string {
		return Schema::AGENTS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'name', 'status', 'created_at', 'updated_at' );
	}

	public function find( int $id ): ?Agent {
		$row = $this->fetchRow( 'id = %d AND deleted_at IS NULL', array( $id ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function findByUuid( Uuid $uuid ): ?Agent {
		$row = $this->fetchRow( 'uuid = %s AND deleted_at IS NULL', array( $uuid->value ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function findBySlug( string $slug ): ?Agent {
		$row = $this->fetchRow( 'slug = %s AND deleted_at IS NULL', array( $slug ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function paginate( Pagination $pagination, array $filters = array() ): array {
		[ $where, $params ] = $this->buildFilters( $filters );

		$rows = $this->fetchAll(
			$where,
			$params,
			'created_at',
			'DESC',
			$pagination->perPage,
			$pagination->offset()
		);

		return array_map( fn ( array $row ): Agent => $this->hydrate( $row ), $rows );
	}

	public function count( array $filters = array() ): int {
		[ $where, $params ] = $this->buildFilters( $filters );

		return $this->countWhere( $where, $params );
	}

	public function published(): array {
		$rows = $this->fetchAll(
			'status = %s AND deleted_at IS NULL',
			array( AgentStatus::Published->value ),
			'created_at',
			'ASC'
		);

		return array_map( fn ( array $row ): Agent => $this->hydrate( $row ), $rows );
	}

	public function slugTaken( string $slug, ?int $exceptId = null ): bool {
		// Soft-deleted clerks still hold their slug, because the unique key
		// does not know about deleted_at. Asking without that condition is
		// what keeps this answer and the database's answer the same.
		if ( null === $exceptId ) {
			return $this->countWhere( 'slug = %s', array( $slug ) ) > 0;
		}

		return $this->countWhere( 'slug = %s AND id != %d', array( $slug, $exceptId ) ) > 0;
	}

	public function save( Agent $agent ): Agent {
		$data = array(
			'uuid'              => $agent->uuid->value,
			'name'              => $agent->name,
			'slug'              => $agent->slug,
			'role_preset'       => $agent->rolePreset,
			'status'            => $agent->status->value,
			'greeting'          => $agent->greeting,
			'fallback_message'  => $agent->fallbackMessage,
			'instructions'      => $agent->instructions,
			'avatar_url'        => $agent->avatarUrl,
			'personality'       => $this->encodeJson( $agent->personality ),
			'model_config'      => $this->encodeJson( $agent->modelConfig ),
			'guardrails'        => $this->encodeJson( $agent->guardrails ),
			'display_rules'     => $this->encodeJson( $agent->displayRulesRaw ),
			'widget_config'     => $this->encodeJson( $agent->widgetConfig ),
			'lead_config'       => $this->encodeJson( $agent->leadConfig ),
			'token_budget'      => $agent->tokenBudget,
			'tokens_used_month' => $agent->tokensUsedMonth,
			'budget_reset_at'   => $this->stamp( $agent->budgetResetAt ),
			'updated_at'        => $this->now(),
		);

		if ( null === $agent->id ) {
			$data['created_at'] = $this->now();
			$data['created_by'] = get_current_user_id() > 0 ? get_current_user_id() : null;

			$id = $this->insertRow( $data );

			if ( null === $id ) {
				return $agent;
			}

			$agent->id = $id;

			return $agent;
		}

		$this->updateRow( $agent->id, $data );

		return $agent;
	}

	public function delete( int $id ): bool {
		return $this->updateRow( $id, array( 'deleted_at' => $this->now() ) );
	}

	public function sourceIds( int $agentId ): array {
		$table = Schema::table( Schema::AGENT_SOURCES );

		$prepared = $this->db->prepare(
			"SELECT source_id FROM `{$table}` WHERE agent_id = %d ORDER BY priority DESC, id ASC",
			$agentId
		);

		if ( ! is_string( $prepared ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $this->db->get_col( $prepared );

		return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array();
	}

	public function sourceCounts( array $agentIds ): array {
		$agentIds = array_values( array_unique( array_map( 'intval', $agentIds ) ) );

		if ( array() === $agentIds ) {
			return array();
		}

		$table        = Schema::table( Schema::AGENT_SOURCES );
		$placeholders = implode( ', ', array_fill( 0, count( $agentIds ), '%d' ) );

		$prepared = $this->db->prepare(
			"SELECT agent_id, COUNT(*) AS total
			 FROM `{$table}`
			 WHERE agent_id IN ({$placeholders})
			 GROUP BY agent_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$agentIds
		);

		if ( ! is_string( $prepared ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $prepared, ARRAY_A );

		$counts = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (int) $row['agent_id'] ] = (int) $row['total'];
		}

		return $counts;
	}

	public function attachSource( int $agentId, int $sourceId, int $priority = 0 ): void {
		$table = Schema::table( Schema::AGENT_SOURCES );

		// INSERT IGNORE against the unique key rather than select-then-insert:
		// two operators attaching the same source at once would otherwise
		// race through the gap between the two statements.
		$this->execute(
			"INSERT IGNORE INTO `{$table}` (agent_id, source_id, priority, created_at) VALUES (%d, %d, %d, %s)",
			array( $agentId, $sourceId, $priority, $this->now() )
		);
	}

	public function syncSources( int $agentId, array $sourceIds ): void {
		$table   = Schema::table( Schema::AGENT_SOURCES );
		$wanted  = array_values( array_unique( array_map( 'intval', $sourceIds ) ) );
		$current = $this->sourceIds( $agentId );

		foreach ( array_diff( $current, $wanted ) as $removed ) {
			$this->execute(
				"DELETE FROM `{$table}` WHERE agent_id = %d AND source_id = %d",
				array( $agentId, $removed )
			);
		}

		// Priority descends with position, so the order the operator
		// arranged the list in is the order retrieval sees.
		$priority = count( $wanted );

		foreach ( $wanted as $sourceId ) {
			$this->execute(
				"INSERT INTO `{$table}` (agent_id, source_id, priority, created_at)
				 VALUES (%d, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE priority = VALUES(priority)",
				array( $agentId, $sourceId, $priority, $this->now() )
			);

			--$priority;
		}
	}

	public function incrementUsage( int $id, int $tokens ): void {
		$table = $this->tableName();

		// Incremented in SQL, not read-modify-write: two concurrent
		// conversations must not lose one another's usage, or the budget cap
		// stops working under exactly the load that makes it matter.
		$this->execute(
			"UPDATE `{$table}` SET tokens_used_month = tokens_used_month + %d WHERE id = %d",
			array( $tokens, $id )
		);
	}

	public function resetUsage( int $id, string $resetAt ): void {
		$table = $this->tableName();

		// Conditional on the stored reset time so two requests arriving at
		// the turn of the month cannot both zero the counter — the second
		// would otherwise discard whatever the first one's conversation
		// had already spent in the new period.
		$this->execute(
			"UPDATE `{$table}`
			 SET tokens_used_month = 0, budget_reset_at = %s
			 WHERE id = %d AND ( budget_reset_at IS NULL OR budget_reset_at < %s )",
			array( $resetAt, $id, $resetAt )
		);
	}

	/**
	 * Turn a filter array into a WHERE clause and bound parameters.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function buildFilters( array $filters ): array {
		$where  = 'deleted_at IS NULL';
		$params = array();

		if ( isset( $filters['status'] ) && is_string( $filters['status'] ) ) {
			$status = AgentStatus::tryFrom( $filters['status'] );

			if ( null !== $status ) {
				$where   .= ' AND status = %s';
				$params[] = $status->value;
			}
		}

		if ( isset( $filters['role_preset'] ) && is_string( $filters['role_preset'] ) && '' !== $filters['role_preset'] ) {
			$where   .= ' AND role_preset = %s';
			$params[] = $filters['role_preset'];
		}

		if ( isset( $filters['search'] ) && is_string( $filters['search'] ) && '' !== trim( $filters['search'] ) ) {
			$where   .= ' AND name LIKE %s';
			$params[] = '%' . $this->db->esc_like( trim( $filters['search'] ) ) . '%';
		}

		return array( $where, $params );
	}

	/**
	 * A DateTimeImmutable as a MySQL DATETIME in UTC, or null.
	 *
	 * @param DateTimeImmutable|null $value Time.
	 * @return string|null
	 */
	private function stamp( ?DateTimeImmutable $value ): ?string {
		return null === $value
			? null
			: $value->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Build an Agent from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return Agent
	 */
	private function hydrate( array $row ): Agent {
		return new Agent(
			id: isset( $row['id'] ) ? (int) $row['id'] : null,
			uuid: new Uuid( (string) ( $row['uuid'] ?? '' ) ),
			name: (string) ( $row['name'] ?? '' ),
			slug: (string) ( $row['slug'] ?? '' ),
			rolePreset: (string) ( $row['role_preset'] ?? 'support' ),
			status: AgentStatus::fromStorage( isset( $row['status'] ) ? (string) $row['status'] : null ),
			greeting: isset( $row['greeting'] ) ? (string) $row['greeting'] : null,
			fallbackMessage: isset( $row['fallback_message'] ) ? (string) $row['fallback_message'] : null,
			instructions: isset( $row['instructions'] ) ? (string) $row['instructions'] : null,
			modelConfig: $this->json( $row['model_config'] ?? null ),
			guardrails: $this->json( $row['guardrails'] ?? null ),
			tokenBudget: isset( $row['token_budget'] ) ? (int) $row['token_budget'] : null,
			tokensUsedMonth: (int) ( $row['tokens_used_month'] ?? 0 ),
			avatarUrl: isset( $row['avatar_url'] ) ? (string) $row['avatar_url'] : null,
			widgetConfig: $this->json( $row['widget_config'] ?? null ),
			personality: $this->json( $row['personality'] ?? null ),
			displayRulesRaw: $this->json( $row['display_rules'] ?? null ),
			leadConfig: $this->json( $row['lead_config'] ?? null ),
			budgetResetAt: $this->time( $row['budget_reset_at'] ?? null ),
			createdAt: $this->time( $row['created_at'] ?? null ),
		);
	}

	/**
	 * Parse a stored DATETIME, which is always UTC.
	 *
	 * @param mixed $value Raw column value.
	 * @return DateTimeImmutable|null
	 */
	private function time( mixed $value ): ?DateTimeImmutable {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}
}
