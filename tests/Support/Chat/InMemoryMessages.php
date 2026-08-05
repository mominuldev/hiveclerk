<?php
/**
 * Message storage without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Chat;

use Hiveclerk\Domain\Conversation\Message;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Message storage without a database.
 */
final class InMemoryMessages implements MessageRepositoryInterface {

	/**
	 * Messages in save order.
	 *
	 * @var array<int, Message>
	 */
	public array $saved = array();

	public function findByUuid( Uuid $uuid ): ?Message {
		foreach ( $this->saved as $message ) {
			if ( $message->uuid->value === $uuid->value ) {
				return $message;
			}
		}

		return null;
	}

	public function transcript( int $conversationId ): array {
		return $this->saved;
	}

	public function recent( int $conversationId, int $limit = 10 ): array {
		return array_slice( $this->saved, -$limit );
	}

	public function save( Message $message ): Message {
		$message->id   = count( $this->saved ) + 1;
		$this->saved[] = $message;

		return $message;
	}

	public function rate( Uuid $uuid, int $rating, ?string $comment = null ): bool {
		return true;
	}

	public function countFor( int $conversationId ): int {
		return count( $this->saved );
	}
}
