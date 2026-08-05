<?php
/**
 * Agent repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

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

	public function paginate( Pagination $pagination, ?AgentStatus $status = null ): array {
		$where  = 'deleted_at IS NULL';
		$params = array();

		if ( null !== $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status->value;
		}

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

	public function count( ?AgentStatus $status = null ): int {
		$where  = 'deleted_at IS NULL';
		$params = array();

		if ( null !== $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status->value;
		}

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
			'model_config'      => $this->encodeJson( $agent->modelConfig ),
			'guardrails'        => $this->encodeJson( $agent->guardrails ),
			'widget_config'     => $this->encodeJson( $agent->widgetConfig ),
			'token_budget'      => $agent->tokenBudget,
			'tokens_used_month' => $agent->tokensUsedMonth,
			'updated_at'        => $this->now(),
		);

		if ( null === $agent->id ) {
			$data['created_at'] = $this->now();

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
		);
	}
}
