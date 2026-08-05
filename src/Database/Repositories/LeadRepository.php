<?php
/**
 * Lead repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStatus;
use Hiveclerk\Domain\Lead\ScoreBand;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Stores leads.
 */
final class LeadRepository extends AbstractRepository implements LeadRepositoryInterface {

	protected function table(): string {
		return Schema::LEADS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'score', 'created_at', 'updated_at', 'last_active_at', 'first_seen_at' );
	}

	public function find( int $id ): ?Lead {
		$row = $this->fetchRow( 'id = %d AND deleted_at IS NULL', array( $id ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function findByUuid( Uuid $uuid ): ?Lead {
		$row = $this->fetchRow( 'uuid = %s AND deleted_at IS NULL', array( $uuid->value ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function findByEmailHash( string $hash ): ?Lead {
		if ( '' === $hash ) {
			return null;
		}

		// Soft-deleted rows are matched deliberately. The unique index does
		// not care that a lead was deleted, so ignoring them here would
		// produce an insert that fails on a duplicate key with nothing on
		// screen to explain it.
		$row = $this->fetchRow( 'email_hash = %s', array( $hash ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function paginate(
		Pagination $pagination,
		array $filters = array(),
		string $orderBy = 'created_at',
		string $order = 'DESC'
	): array {
		[ $where, $params ] = $this->buildFilters( $filters );

		$rows = $this->fetchAll(
			$where,
			$params,
			$orderBy,
			$order,
			$pagination->perPage,
			$pagination->offset()
		);

		return array_map( fn ( array $row ): Lead => $this->hydrate( $row ), $rows );
	}

	public function count( array $filters = array() ): int {
		[ $where, $params ] = $this->buildFilters( $filters );

		return $this->countWhere( $where, $params );
	}

	public function countsByStage( array $filters = array() ): array {
		[ $where, $params ] = $this->buildFilters( $filters );

		$table = $this->tableName();
		$sql   = "SELECT COALESCE(stage_id, 0) AS stage, COUNT(*) AS total
			 FROM `{$table}` WHERE {$where} GROUP BY COALESCE(stage_id, 0)";

		if ( array() !== $params ) {
			$sql = $this->db->prepare( $sql, ...$params );
		}

		if ( ! is_string( $sql ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows   = $this->db->get_results( $sql, ARRAY_A );
		$counts = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (int) $row['stage'] ] = (int) $row['total'];
		}

		return $counts;
	}

	public function batch( array $filters, int $limit, int $offset ): array {
		[ $where, $params ] = $this->buildFilters( $filters );

		$rows = $this->fetchAll( $where, $params, 'id', 'ASC', $limit, $offset );

		return array_map( fn ( array $row ): Lead => $this->hydrate( $row ), $rows );
	}

	public function save( Lead $lead ): Lead {
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$data = array(
			'email'          => $lead->email,
			'email_hash'     => $lead->emailHash,
			'first_name'     => $lead->firstName,
			'last_name'      => $lead->lastName,
			'phone'          => $lead->phone,
			'company'        => $lead->company,
			'job_title'      => $lead->jobTitle,
			'website'        => $lead->website,
			'wp_user_id'     => $lead->wpUserId,
			'stage_id'       => $lead->stageId,
			'score'          => $lead->score,
			'score_band'     => $lead->band->value,
			'status'         => $lead->status->value,
			'source'         => $lead->source,
			'custom_fields'  => $this->encodeJson( $lead->customFields ),
			'consent'        => $this->encodeJson( $lead->consent ),
			'owner_user_id'  => $lead->ownerUserId,
			'last_active_at' => $this->stamp( $lead->lastActiveAt ),
			'converted_at'   => $this->stamp( $lead->convertedAt ),
			'updated_at'     => $this->stamp( $now ),
		);

		$lead->updatedAt = $now;

		if ( null === $lead->id ) {
			$firstSeen = $lead->firstSeenAt ?? $now;

			$data['uuid']          = $lead->uuid->value;
			$data['first_seen_at'] = $this->stamp( $firstSeen );
			$data['created_at']    = $this->stamp( $lead->createdAt ?? $now );

			$lead->firstSeenAt = $firstSeen;
			$lead->createdAt   = $lead->createdAt ?? $now;

			$id = $this->insertRow( $data );

			if ( null === $id ) {
				return $lead;
			}

			$lead->id = $id;

			return $lead;
		}

		$this->updateRow( $lead->id, $data );

		return $lead;
	}

	public function updateScore( int $id, int $score, ScoreBand $band ): void {
		$this->updateRow(
			$id,
			array(
				'score'      => $score,
				'score_band' => $band->value,
				'updated_at' => $this->now(),
			)
		);
	}

	public function reassignStage( int $from, ?int $to ): int {
		$table = $this->tableName();

		// Null cannot be bound through a %d placeholder, so the two cases
		// are two statements rather than one with a conditional argument.
		$done = null === $to
			? $this->execute( "UPDATE `{$table}` SET stage_id = NULL WHERE stage_id = %d", array( $from ) )
			: $this->execute( "UPDATE `{$table}` SET stage_id = %d WHERE stage_id = %d", array( $to, $from ) );

		return $done ? (int) $this->db->rows_affected : 0;
	}

	/**
	 * Delete a lead and everything hanging off it.
	 *
	 * A hard delete, in a transaction, with the children first. There are
	 * no database-level foreign keys, so an interrupted delete would
	 * otherwise leave score events attributing points to a lead that no
	 * longer exists — and the scoring screen would show them.
	 *
	 * Visitors are detached rather than deleted. They hold nothing
	 * identifying, and the site's traffic record should not disappear
	 * because somebody tidied their pipeline.
	 *
	 * @param int $id Storage id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		$table         = $this->tableName();
		$scores        = Schema::table( Schema::LEAD_SCORES );
		$activities    = Schema::table( Schema::ACTIVITIES );
		$visitors      = Schema::table( Schema::VISITORS );
		$conversations = Schema::table( Schema::CONVERSATIONS );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->db->query( 'START TRANSACTION' );

		$done = $this->execute( "DELETE FROM `{$scores}` WHERE lead_id = %d", array( $id ) );
		$done = $done && $this->execute( "DELETE FROM `{$activities}` WHERE lead_id = %d", array( $id ) );
		$done = $done && $this->execute( "UPDATE `{$visitors}` SET lead_id = NULL WHERE lead_id = %d", array( $id ) );
		$done = $done && $this->execute( "UPDATE `{$conversations}` SET lead_id = NULL WHERE lead_id = %d", array( $id ) );
		$done = $done && $this->execute( "DELETE FROM `{$table}` WHERE id = %d", array( $id ) );

		$this->db->query( $done ? 'COMMIT' : 'ROLLBACK' );
		// phpcs:enable

		return $done;
	}

	/**
	 * Turn a filter array into a WHERE clause and bound parameters.
	 *
	 * Filter keys are matched against a fixed set; an unknown key is
	 * ignored rather than interpolated.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function buildFilters( array $filters ): array {
		$where  = 'deleted_at IS NULL';
		$params = array();

		if ( isset( $filters['stage_id'] ) ) {
			if ( 'none' === $filters['stage_id'] ) {
				$where .= ' AND stage_id IS NULL';
			} elseif ( is_numeric( $filters['stage_id'] ) ) {
				$where   .= ' AND stage_id = %d';
				$params[] = (int) $filters['stage_id'];
			}
		}

		if ( isset( $filters['status'] ) && is_string( $filters['status'] ) ) {
			$status = LeadStatus::tryFrom( $filters['status'] );

			if ( null !== $status ) {
				$where   .= ' AND status = %s';
				$params[] = $status->value;
			}
		}

		if ( isset( $filters['band'] ) && is_string( $filters['band'] ) ) {
			$band = ScoreBand::tryFrom( $filters['band'] );

			if ( null !== $band ) {
				$where   .= ' AND score_band = %s';
				$params[] = $band->value;
			}
		}

		if ( isset( $filters['owner_user_id'] ) ) {
			if ( 'none' === $filters['owner_user_id'] ) {
				$where .= ' AND owner_user_id IS NULL';
			} elseif ( is_numeric( $filters['owner_user_id'] ) ) {
				$where   .= ' AND owner_user_id = %d';
				$params[] = (int) $filters['owner_user_id'];
			}
		}

		if ( isset( $filters['source'] ) && is_string( $filters['source'] ) && '' !== $filters['source'] ) {
			$where   .= ' AND source = %s';
			$params[] = $filters['source'];
		}

		if ( isset( $filters['min_score'] ) && is_numeric( $filters['min_score'] ) ) {
			$where   .= ' AND score >= %d';
			$params[] = (int) $filters['min_score'];
		}

		if ( isset( $filters['has_email'] ) ) {
			$where .= $filters['has_email'] ? ' AND email_hash IS NOT NULL' : ' AND email_hash IS NULL';
		}

		if ( isset( $filters['search'] ) && is_string( $filters['search'] ) && '' !== trim( $filters['search'] ) ) {
			$like     = '%' . $this->db->esc_like( trim( $filters['search'] ) ) . '%';
			$where   .= ' AND ( email LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR company LIKE %s OR phone LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( isset( $filters['date_from'] ) && is_string( $filters['date_from'] ) ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $filters['date_from'];
		}

		if ( isset( $filters['date_to'] ) && is_string( $filters['date_to'] ) ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $filters['date_to'];
		}

		return array( $where, $params );
	}



	/**
	 * Build a Lead from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return Lead
	 */
	private function hydrate( array $row ): Lead {
		return new Lead(
			id: (int) $row['id'],
			uuid: new Uuid( (string) $row['uuid'] ),
			email: $this->text( $row['email'] ?? null ),
			emailHash: $this->text( $row['email_hash'] ?? null ),
			firstName: $this->text( $row['first_name'] ?? null ),
			lastName: $this->text( $row['last_name'] ?? null ),
			phone: $this->text( $row['phone'] ?? null ),
			company: $this->text( $row['company'] ?? null ),
			jobTitle: $this->text( $row['job_title'] ?? null ),
			website: $this->text( $row['website'] ?? null ),
			wpUserId: $this->intOrNull( $row['wp_user_id'] ?? null ),
			stageId: $this->intOrNull( $row['stage_id'] ?? null ),
			score: (int) ( $row['score'] ?? 0 ),
			band: ScoreBand::fromStorage( $this->text( $row['score_band'] ?? null ) ),
			status: LeadStatus::fromStorage( $this->text( $row['status'] ?? null ) ),
			source: $this->text( $row['source'] ?? null ),
			customFields: $this->json( $row['custom_fields'] ?? null ),
			consent: $this->json( $row['consent'] ?? null ),
			ownerUserId: $this->intOrNull( $row['owner_user_id'] ?? null ),
			firstSeenAt: $this->time( $row['first_seen_at'] ?? null ),
			lastActiveAt: $this->time( $row['last_active_at'] ?? null ),
			convertedAt: $this->time( $row['converted_at'] ?? null ),
			createdAt: $this->time( $row['created_at'] ?? null ),
			updatedAt: $this->time( $row['updated_at'] ?? null ),
		);
	}
}
