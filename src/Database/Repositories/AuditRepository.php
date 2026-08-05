<?php
/**
 * Audit log persistence.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Audit\AuditEntry;
use Hiveclerk\Domain\Audit\AuditRepositoryInterface;
use Hiveclerk\Domain\Shared\Pagination;

/**
 * Append-only audit storage.
 */
final class AuditRepository extends AbstractRepository implements AuditRepositoryInterface {

	/**
	 * Table constant.
	 *
	 * @return string
	 */
	protected function table(): string {
		return Schema::AUDIT_LOG;
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<int, string>
	 */
	protected function sortableColumns(): array {
		return array( 'created_at' );
	}

	/**
	 * Append a record.
	 *
	 * @param AuditEntry $entry Entry.
	 * @return void
	 */
	public function append( AuditEntry $entry ): void {
		$this->insertRow(
			array(
				'wp_user_id'  => $entry->userId,
				'action'      => $entry->action,
				'object_type' => $entry->objectType,
				'object_id'   => $entry->objectId,
				'changes'     => $this->encodeJson( $entry->changes ),
				'ip_hash'     => $entry->ipHash,
				'user_agent'  => $entry->userAgent,
				'created_at'  => '' !== $entry->createdAt ? $entry->createdAt : $this->now(),
			)
		);
	}

	/**
	 * A page of records, newest first.
	 *
	 * @param Pagination  $pagination Page request.
	 * @param string|null $action     Filter by exact action.
	 * @param int|null    $userId     Filter by user.
	 * @return array<int, AuditEntry>
	 */
	public function paginate(
		Pagination $pagination,
		?string $action = null,
		?int $userId = null
	): array {
		[ $where, $params ] = self::filter( $action, $userId );

		$rows = $this->fetchAll(
			$where,
			$params,
			'created_at',
			'DESC',
			$pagination->perPage,
			$pagination->offset()
		);

		return array_map( self::hydrate( ... ), $rows );
	}

	/**
	 * Total records matching a filter.
	 *
	 * @param string|null $action Filter by exact action.
	 * @param int|null    $userId Filter by user.
	 * @return int
	 */
	public function total( ?string $action = null, ?int $userId = null ): int {
		[ $where, $params ] = self::filter( $action, $userId );

		return $this->countWhere( $where, $params );
	}

	/**
	 * Distinct action names present in the log.
	 *
	 * Read from the data rather than from a hard-coded list so a filter
	 * dropdown never offers an action this install has never performed,
	 * and never omits one added by a third-party module.
	 *
	 * @return array<int, string>
	 */
	public function actions(): array {
		$table = $this->tableName();

		// No placeholders: the statement has no variable parts.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_col( "SELECT DISTINCT action FROM `{$table}` ORDER BY action ASC LIMIT 200" );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $rows ) ) );
	}

	/**
	 * Delete records older than a cut-off.
	 *
	 * @param string $before Exclusive UTC date.
	 * @return int
	 */
	public function purgeBefore( string $before ): int {
		$table = $this->tableName();

		$this->execute(
			"DELETE FROM `{$table}` WHERE created_at < %s",
			array( $before . ' 00:00:00' )
		);

		return (int) $this->db->rows_affected;
	}

	/**
	 * Build a WHERE clause and its parameters.
	 *
	 * @param string|null $action Action filter.
	 * @param int|null    $userId User filter.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private static function filter( ?string $action, ?int $userId ): array {
		$where  = '1=1';
		$params = array();

		if ( null !== $action && '' !== $action ) {
			$where   .= ' AND action = %s';
			$params[] = $action;
		}

		if ( null !== $userId ) {
			$where   .= ' AND wp_user_id = %d';
			$params[] = $userId;
		}

		return array( $where, $params );
	}

	/**
	 * Build an entry from a row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return AuditEntry
	 */
	private function hydrate( array $row ): AuditEntry {
		return new AuditEntry(
			action: (string) ( $row['action'] ?? '' ),
			userId: isset( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : null,
			objectType: isset( $row['object_type'] ) && is_string( $row['object_type'] )
				? $row['object_type']
				: null,
			objectId: isset( $row['object_id'] ) ? (int) $row['object_id'] : null,
			changes: $this->json( $row['changes'] ?? null ),
			ipHash: isset( $row['ip_hash'] ) && is_string( $row['ip_hash'] ) ? $row['ip_hash'] : null,
			userAgent: isset( $row['user_agent'] ) && is_string( $row['user_agent'] )
				? $row['user_agent']
				: null,
			createdAt: (string) ( $row['created_at'] ?? '' )
		);
	}
}
