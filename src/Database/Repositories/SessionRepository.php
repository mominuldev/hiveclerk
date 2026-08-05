<?php
/**
 * Session repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Conversation\Session;
use Hiveclerk\Domain\Conversation\SessionRepositoryInterface;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Stores widget sessions.
 */
final class SessionRepository extends AbstractRepository implements SessionRepositoryInterface {

	protected function table(): string {
		return Schema::SESSIONS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'created_at', 'expires_at' );
	}

	public function findByTokenHash( string $tokenHash ): ?Session {
		$row = $this->fetchRow( 'token_hash = %s', array( $tokenHash ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function save( Session $session ): Session {
		$data = array(
			'uuid'            => $session->uuid->value,
			'visitor_id'      => $session->visitorId,
			'conversation_id' => $session->conversationId,
			'token_hash'      => $session->tokenHash,
			'transport'       => $session->transport,
			'ip_hash'         => $session->ipHash,
			'expires_at'      => ( $session->expiresAt ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
				->format( 'Y-m-d H:i:s' ),
		);

		if ( null === $session->id ) {
			$data['created_at'] = $this->now();

			$id = $this->insertRow( $data );

			if ( null === $id ) {
				return $session;
			}

			$session->id = $id;

			return $session;
		}

		$this->updateRow( $session->id, $data );

		return $session;
	}

	public function recordTransport( int $id, string $transport ): void {
		// Whitelisted rather than trusted. The value arrives from the widget,
		// which is code we ship but not code we control at runtime.
		$value = in_array( $transport, array( 'sse', 'poll' ), true ) ? $transport : 'sse';

		$this->updateRow( $id, array( 'transport' => $value ) );
	}

	public function purgeExpired( string $before, int $limit = 500 ): int {
		$table = $this->tableName();

		$prepared = $this->db->prepare(
			"DELETE FROM `{$table}` WHERE expires_at < %s LIMIT %d",
			$before,
			$limit
		);

		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->query( $prepared );
	}

	/**
	 * Build a Session from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return Session
	 */
	private function hydrate( array $row ): Session {
		return new Session(
			id: isset( $row['id'] ) ? (int) $row['id'] : null,
			uuid: new Uuid( (string) ( $row['uuid'] ?? '' ) ),
			tokenHash: (string) ( $row['token_hash'] ?? '' ),
			conversationId: isset( $row['conversation_id'] ) ? (int) $row['conversation_id'] : null,
			visitorId: isset( $row['visitor_id'] ) ? (int) $row['visitor_id'] : null,
			transport: (string) ( $row['transport'] ?? 'sse' ),
			ipHash: isset( $row['ip_hash'] ) ? (string) $row['ip_hash'] : null,
			expiresAt: $this->utc( $row['expires_at'] ?? null ),
			createdAt: $this->utc( $row['created_at'] ?? null ),
		);
	}

	/**
	 * Parse a stored DATETIME as UTC.
	 *
	 * @param mixed $value Raw column value.
	 * @return DateTimeImmutable|null
	 */
	private function utc( mixed $value ): ?DateTimeImmutable {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}
}
