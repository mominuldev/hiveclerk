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

	public function paginate( Pagination $pagination, ?AgentStatus $status = null ): array {
		return array_values( $this->agents );
	}

	public function count( ?AgentStatus $status = null ): int {
		return count( $this->agents );
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

	public function incrementUsage( int $id, int $tokens ): void {
		$this->charged += $tokens;
	}
}
