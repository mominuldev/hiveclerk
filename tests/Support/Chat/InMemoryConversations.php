<?php
/**
 * Conversation storage without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Chat;

use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Conversation storage without a database.
 */
final class InMemoryConversations implements ConversationRepositoryInterface {

	/**
	 * Conversations by id.
	 *
	 * @var array<int, Conversation>
	 */
	public array $saved = array();

	/**
	 * Ids passed to purge().
	 *
	 * @var array<int, int>
	 */
	public array $purged = array();

	public function find( int $id ): ?Conversation {
		return $this->saved[ $id ] ?? null;
	}

	public function findByUuid( Uuid $uuid ): ?Conversation {
		foreach ( $this->saved as $conversation ) {
			if ( $conversation->uuid->value === $uuid->value ) {
				return $conversation;
			}
		}

		return null;
	}

	public function paginate( Pagination $pagination, array $filters = array() ): array {
		return array_values( $this->saved );
	}

	public function count( array $filters = array() ): int {
		return count( $this->saved );
	}

	public function save( Conversation $conversation ): Conversation {
		$conversation->id                 = $conversation->id ?? count( $this->saved ) + 1;
		$this->saved[ $conversation->id ] = $conversation;

		return $conversation;
	}

	public function delete( int $id ): bool {
		unset( $this->saved[ $id ] );

		return true;
	}

	public function awaitingHandoff( int $limit = 20 ): array {
		return array();
	}

	public function forLead( int $leadId, int $limit = 20 ): array {
		$found = array();

		foreach ( $this->saved as $conversation ) {
			if ( $conversation->leadId === $leadId ) {
				$found[] = $conversation;
			}

			if ( count( $found ) >= $limit ) {
				break;
			}
		}

		return $found;
	}

	public function attachLead( int $conversationId, int $leadId ): bool {
		if ( ! isset( $this->saved[ $conversationId ] ) ) {
			return false;
		}

		$this->saved[ $conversationId ]->leadId = $leadId;

		return true;
	}

	public function reassignLead( int $from, int $to ): int {
		$moved = 0;

		foreach ( $this->saved as $conversation ) {
			if ( $conversation->leadId === $from ) {
				$conversation->leadId = $to;
				++$moved;
			}
		}

		return $moved;
	}

	public function idsStartedBefore( string $cutoff, int $limit ): array {
		$ids = array();

		foreach ( $this->saved as $id => $conversation ) {
			if ( null !== $conversation->startedAt && $conversation->startedAt->format( 'Y-m-d H:i:s' ) < $cutoff ) {
				$ids[] = $id;
			}

			if ( count( $ids ) >= $limit ) {
				break;
			}
		}

		return $ids;
	}

	public function countStartedBefore( string $cutoff ): int {
		return count( $this->idsStartedBefore( $cutoff, PHP_INT_MAX ) );
	}

	public function purge( array $ids ): int {
		$deleted = 0;

		foreach ( $ids as $id ) {
			if ( isset( $this->saved[ $id ] ) ) {
				unset( $this->saved[ $id ] );
				++$deleted;
			}
		}

		$this->purged = array_merge( $this->purged, $ids );

		return $deleted;
	}

	public function statsForAgents( array $agentIds, string $since ): array {
		return array();
	}
}
