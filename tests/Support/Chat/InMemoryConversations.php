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
}
