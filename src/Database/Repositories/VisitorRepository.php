<?php
/**
 * Visitor repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Lead\Visitor;
use Hiveclerk\Domain\Lead\VisitorRepositoryInterface;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Stores anonymous visitors.
 */
final class VisitorRepository extends AbstractRepository implements VisitorRepositoryInterface {

	protected function table(): string {
		return Schema::VISITORS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'last_seen_at', 'first_seen_at', 'page_views' );
	}

	public function find( int $id ): ?Visitor {
		$row = $this->fetchRow( 'id = %d', array( $id ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function findByUuid( Uuid $uuid ): ?Visitor {
		$row = $this->fetchRow( 'uuid = %s', array( $uuid->value ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function save( Visitor $visitor ): Visitor {
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$data = array(
			'wp_user_id'    => $visitor->wpUserId,
			'lead_id'       => $visitor->leadId,
			'fingerprint'   => $visitor->fingerprint,
			'ip_hash'       => $visitor->ipHash,
			'user_agent'    => null === $visitor->userAgent ? null : mb_substr( $visitor->userAgent, 0, 500 ),
			'country'       => $visitor->country,
			'language'      => $visitor->language,
			'page_views'    => $visitor->pageViews,
			'session_count' => $visitor->sessionCount,
			'metadata'      => $this->encodeJson( $visitor->metadata ),
			'last_seen_at'  => $this->stamp( $visitor->lastSeenAt ?? $now ),
		);

		$visitor->lastSeenAt = $visitor->lastSeenAt ?? $now;

		if ( null === $visitor->id ) {
			$first = $visitor->firstSeenAt ?? $now;

			$data['uuid']          = $visitor->uuid->value;
			$data['first_seen_at'] = $this->stamp( $first );

			$visitor->firstSeenAt = $first;

			$id = $this->insertRow( $data );

			if ( null === $id ) {
				return $visitor;
			}

			$visitor->id = $id;

			return $visitor;
		}

		$this->updateRow( $visitor->id, $data );

		return $visitor;
	}

	public function forLead( int $leadId ): array {
		$rows = $this->fetchAll( 'lead_id = %d', array( $leadId ), 'last_seen_at', 'DESC', 50 );

		return array_map( fn ( array $row ): Visitor => $this->hydrate( $row ), $rows );
	}

	public function attachToLead( array $ids, int $leadId ): int {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( array() === $ids ) {
			return 0;
		}

		$table = $this->tableName();

		// Placeholders come from the count, never from a value, so nothing
		// but %d reaches the statement.
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$done = $this->execute(
			"UPDATE `{$table}` SET lead_id = %d WHERE id IN ({$placeholders})",
			array_merge( array( $leadId ), $ids )
		);

		return $done ? (int) $this->db->rows_affected : 0;
	}

	public function reassign( int $from, int $to ): int {
		$table = $this->tableName();

		$done = $this->execute(
			"UPDATE `{$table}` SET lead_id = %d WHERE lead_id = %d",
			array( $to, $from )
		);

		return $done ? (int) $this->db->rows_affected : 0;
	}

	public function detachLead( int $leadId ): int {
		$table = $this->tableName();

		$done = $this->execute( "UPDATE `{$table}` SET lead_id = NULL WHERE lead_id = %d", array( $leadId ) );

		return $done ? (int) $this->db->rows_affected : 0;
	}


	/**
	 * Build a Visitor from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return Visitor
	 */
	private function hydrate( array $row ): Visitor {
		return new Visitor(
			id: (int) $row['id'],
			uuid: new Uuid( (string) $row['uuid'] ),
			leadId: $this->intOrNull( $row['lead_id'] ?? null ),
			wpUserId: $this->intOrNull( $row['wp_user_id'] ?? null ),
			fingerprint: $this->text( $row['fingerprint'] ?? null ),
			ipHash: $this->text( $row['ip_hash'] ?? null ),
			userAgent: $this->text( $row['user_agent'] ?? null ),
			country: $this->text( $row['country'] ?? null ),
			language: $this->text( $row['language'] ?? null ),
			pageViews: (int) ( $row['page_views'] ?? 0 ),
			sessionCount: (int) ( $row['session_count'] ?? 1 ),
			metadata: $this->json( $row['metadata'] ?? null ),
			firstSeenAt: $this->time( $row['first_seen_at'] ?? null ),
			lastSeenAt: $this->time( $row['last_seen_at'] ?? null ),
		);
	}
}
