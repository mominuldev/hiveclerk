<?php
/**
 * Clerk storage without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Chat;

use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Agent\AgentStatus;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Clerk storage without a database.
 */
final class InMemoryAgents implements AgentRepositoryInterface {

	/**
	 * Sources every clerk is given.
	 *
	 * @var array<int, int>
	 */
	public array $sources = array();

	/**
	 * Tokens charged through incrementUsage().
	 *
	 * @var int
	 */
	public int $charged = 0;

	/**
	 * Clerks by id.
	 *
	 * @var array<int, Agent>
	 */
	public array $agents = array();

	/**
	 * Reset timestamps passed to resetUsage().
	 *
	 * @var array<int, string>
	 */
	public array $resets = array();

	public function find( int $id ): ?Agent {
		return $this->agents[ $id ] ?? null;
	}

	public function findByUuid( Uuid $uuid ): ?Agent {
		foreach ( $this->agents as $agent ) {
			if ( $agent->uuid->value === $uuid->value ) {
				return $agent;
			}
		}

		return null;
	}

	public function findBySlug( string $slug ): ?Agent {
		foreach ( $this->agents as $agent ) {
			if ( $agent->slug === $slug ) {
				return $agent;
			}
		}

		return null;
	}

	public function paginate( Pagination $pagination, array $filters = array() ): array {
		return array_values( $this->filtered( $filters ) );
	}

	public function count( array $filters = array() ): int {
		return count( $this->filtered( $filters ) );
	}

	public function slugTaken( string $slug, ?int $exceptId = null ): bool {
		foreach ( $this->agents as $agent ) {
			if ( $agent->slug === $slug && $agent->id !== $exceptId ) {
				return true;
			}
		}

		return false;
	}

	public function published(): array {
		return array_values(
			array_filter( $this->agents, static fn ( Agent $agent ): bool => $agent->status->isServing() )
		);
	}

	public function save( Agent $agent ): Agent {
		$agent->id                  = $agent->id ?? count( $this->agents ) + 1;
		$this->agents[ $agent->id ] = $agent;

		return $agent;
	}

	public function delete( int $id ): bool {
		unset( $this->agents[ $id ] );

		return true;
	}

	public function sourceIds( int $agentId ): array {
		return $this->sources;
	}

	public function attachSource( int $agentId, int $sourceId, int $priority = 0 ): void {
		$this->sources[] = $sourceId;
	}

	public function sourceCounts( array $agentIds ): array {
		$counts = array();

		foreach ( $agentIds as $agentId ) {
			$counts[ (int) $agentId ] = count( $this->sources );
		}

		return $counts;
	}

	public function syncSources( int $agentId, array $sourceIds ): void {
		$this->sources = array_values( array_map( 'intval', $sourceIds ) );
	}

	public function incrementUsage( int $id, int $tokens ): void {
		$this->charged += $tokens;
	}

	public function resetUsage( int $id, string $resetAt ): void {
		$agent = $this->agents[ $id ] ?? null;

		if ( null === $agent ) {
			return;
		}

		$agent->tokensUsedMonth = 0;
		$this->resets[]         = $resetAt;
	}

	/**
	 * Clerks matching a status filter.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, Agent>
	 */
	private function filtered( array $filters ): array {
		$status = isset( $filters['status'] ) && is_string( $filters['status'] )
			? AgentStatus::tryFrom( $filters['status'] )
			: null;

		if ( null === $status ) {
			return $this->agents;
		}

		return array_filter( $this->agents, static fn ( Agent $agent ): bool => $agent->status === $status );
	}
}
