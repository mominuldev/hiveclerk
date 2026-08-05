<?php
/**
 * Message repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Conversation\Message;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Conversation\MessageRole;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Stores messages.
 */
final class MessageRepository extends AbstractRepository implements MessageRepositoryInterface {

	protected function table(): string {
		return Schema::MESSAGES;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'created_at' );
	}

	public function findByUuid( Uuid $uuid ): ?Message {
		$row = $this->fetchRow( 'uuid = %s', array( $uuid->value ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function transcript( int $conversationId ): array {
		$rows = $this->fetchAll(
			'conversation_id = %d',
			array( $conversationId ),
			'created_at',
			'ASC'
		);

		return array_map( fn ( array $row ): Message => $this->hydrate( $row ), $rows );
	}

	/**
	 * The most recent turns, returned oldest first.
	 *
	 * Fetched newest-first so the LIMIT takes the latest turns, then
	 * reversed, because a prompt needs them in chronological order.
	 *
	 * @param int $conversationId Conversation.
	 * @param int $limit          Maximum turns.
	 * @return array<int, Message>
	 */
	public function recent( int $conversationId, int $limit = 10 ): array {
		$rows = $this->fetchAll(
			'conversation_id = %d AND role != %s',
			array( $conversationId, MessageRole::System->value ),
			'created_at',
			'DESC',
			$limit
		);

		$messages = array_map( fn ( array $row ): Message => $this->hydrate( $row ), $rows );

		return array_reverse( $messages );
	}

	public function save( Message $message ): Message {
		$data = array(
			'uuid'            => $message->uuid->value,
			'conversation_id' => $message->conversationId,
			'role'            => $message->role->value,
			'content'         => $message->content,
			'provider'        => $message->provider,
			'model'           => $message->model,
			'tokens_in'       => $message->tokensIn,
			'tokens_out'      => $message->tokensOut,
			'cost'            => $message->cost,
			'latency_ms'      => $message->latencyMs,
			'retrieval_score' => $message->retrievalScore,
			'is_grounded'     => $message->isGrounded ? 1 : 0,
			'guardrail_flags' => $this->encodeJson( $message->guardrailFlags ),
			'created_at'      => $this->now(),
		);

		$id = $this->insertRow( $data );

		if ( null !== $id ) {
			$message->id = $id;
		}

		return $message;
	}

	public function rate( Uuid $uuid, int $rating, ?string $comment = null ): bool {
		$normalised = $rating >= 0 ? 1 : -1;
		$table      = $this->tableName();

		return $this->execute(
			"UPDATE `{$table}` SET rating = %d, rating_comment = %s WHERE uuid = %s",
			array( $normalised, $comment, $uuid->value )
		);
	}

	public function countFor( int $conversationId ): int {
		return $this->countWhere( 'conversation_id = %d', array( $conversationId ) );
	}

	/**
	 * Build a Message from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return Message
	 */
	private function hydrate( array $row ): Message {
		$createdAt = null;

		if ( isset( $row['created_at'] ) && is_string( $row['created_at'] ) ) {
			$createdAt = new DateTimeImmutable( $row['created_at'], new DateTimeZone( 'UTC' ) );
		}

		return new Message(
			id: isset( $row['id'] ) ? (int) $row['id'] : null,
			uuid: new Uuid( (string) ( $row['uuid'] ?? '' ) ),
			conversationId: (int) ( $row['conversation_id'] ?? 0 ),
			role: MessageRole::fromStorage( isset( $row['role'] ) ? (string) $row['role'] : null ),
			content: (string) ( $row['content'] ?? '' ),
			provider: isset( $row['provider'] ) ? (string) $row['provider'] : null,
			model: isset( $row['model'] ) ? (string) $row['model'] : null,
			tokensIn: (int) ( $row['tokens_in'] ?? 0 ),
			tokensOut: (int) ( $row['tokens_out'] ?? 0 ),
			cost: (float) ( $row['cost'] ?? 0 ),
			latencyMs: isset( $row['latency_ms'] ) ? (int) $row['latency_ms'] : null,
			retrievalScore: isset( $row['retrieval_score'] ) ? (float) $row['retrieval_score'] : null,
			isGrounded: (bool) ( $row['is_grounded'] ?? false ),
			rating: isset( $row['rating'] ) ? (int) $row['rating'] : null,
			createdAt: $createdAt,
			guardrailFlags: array_values(
				array_filter(
					$this->json( $row['guardrail_flags'] ?? null ),
					static fn ( $flag ): bool => is_string( $flag )
				)
			),
		);
	}
}
