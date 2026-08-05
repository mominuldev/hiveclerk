<?php
/**
 * Repository base class.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database;

use wpdb;

/**
 * Shared persistence helpers.
 *
 * This layer is the only place in the codebase that touches $wpdb. The
 * hiveclerk.noGlobalWpdb PHPStan rule fails the build if anything else
 * tries, which is what makes "every query is prepared" a checkable claim
 * rather than a convention.
 */
abstract class AbstractRepository {

	/**
	 * WordPress database handle.
	 *
	 * @var wpdb
	 */
	protected wpdb $db;

	/**
	 * Construct.
	 */
	public function __construct() {
		global $wpdb;

		$this->db = $wpdb;
	}

	/**
	 * Schema constant for this repository's table.
	 *
	 * @return string
	 */
	abstract protected function table(): string;

	/**
	 * Columns permitted in ORDER BY.
	 *
	 * Whitelisted rather than escaped: an identifier cannot be
	 * parameterised, so the only safe approach is to never accept one from
	 * a caller in the first place.
	 *
	 * @return array<int, string>
	 */
	abstract protected function sortableColumns(): array;

	/**
	 * Fully prefixed table name.
	 *
	 * @return string
	 */
	protected function tableName(): string {
		return Schema::table( $this->table() );
	}

	/**
	 * Fetch one row.
	 *
	 * @param string             $where  WHERE clause with %s/%d placeholders.
	 * @param array<int, mixed>  $params Placeholder values.
	 * @return array<string, mixed>|null
	 */
	protected function fetchRow( string $where, array $params = array() ): ?array {
		$table = $this->tableName();
		$sql   = "SELECT * FROM `{$table}` WHERE {$where} LIMIT 1";

		if ( array() !== $params ) {
			$sql = $this->db->prepare( $sql, ...$params );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->db->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fetch many rows.
	 *
	 * @param string            $where   WHERE clause, or '1=1'.
	 * @param array<int, mixed> $params  Placeholder values.
	 * @param string|null       $orderBy Column, validated against the whitelist.
	 * @param string            $order   ASC or DESC.
	 * @param int|null          $limit   Row limit.
	 * @param int               $offset  Row offset.
	 * @return array<int, array<string, mixed>>
	 */
	protected function fetchAll(
		string $where = '1=1',
		array $params = array(),
		?string $orderBy = null,
		string $order = 'DESC',
		?int $limit = null,
		int $offset = 0
	): array {
		$table = $this->tableName();
		$sql   = "SELECT * FROM `{$table}` WHERE {$where}";

		$sql .= $this->orderClause( $orderBy, $order );

		if ( null !== $limit ) {
			$params[] = $limit;
			$params[] = $offset;
			$sql     .= ' LIMIT %d OFFSET %d';
		}

		if ( array() !== $params ) {
			$sql = $this->db->prepare( $sql, ...$params );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count rows.
	 *
	 * @param string            $where  WHERE clause.
	 * @param array<int, mixed> $params Placeholder values.
	 * @return int
	 */
	protected function countWhere( string $where = '1=1', array $params = array() ): int {
		$table = $this->tableName();
		$sql   = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";

		if ( array() !== $params ) {
			$sql = $this->db->prepare( $sql, ...$params );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db->get_var( $sql );
	}

	/**
	 * Insert a row.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int|null New id, or null on failure.
	 */
	protected function insertRow( array $data ): ?int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->db->insert( $this->tableName(), $data );

		if ( false === $result ) {
			return null;
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Update a row by id.
	 *
	 * @param int                  $id   Row id.
	 * @param array<string, mixed> $data Column values.
	 * @return bool
	 */
	protected function updateRow( int $id, array $data ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->db->update( $this->tableName(), $data, array( 'id' => $id ) );

		return false !== $result;
	}

	/**
	 * Delete a row by id.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	protected function deleteRow( int $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $this->db->delete( $this->tableName(), array( 'id' => $id ) );
	}

	/**
	 * Run a prepared statement that returns no rows.
	 *
	 * wpdb::prepare() returns null when the placeholder count does not match
	 * the arguments. Passing that straight to query() would send the literal
	 * string "null" to MySQL, so the result is checked before it is used.
	 *
	 * @param string            $sql    Statement with placeholders.
	 * @param array<int, mixed> $params Placeholder values.
	 * @return bool
	 */
	protected function execute( string $sql, array $params ): bool {
		$prepared = $this->db->prepare( $sql, ...$params );

		if ( ! is_string( $prepared ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $this->db->query( $prepared );
	}

	/**
	 * Build a safe ORDER BY clause.
	 *
	 * @param string|null $column Requested column.
	 * @param string      $order  Requested direction.
	 * @return string
	 */
	protected function orderClause( ?string $column, string $order ): string {
		if ( null === $column || ! in_array( $column, $this->sortableColumns(), true ) ) {
			return '';
		}

		$direction = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';

		return " ORDER BY `{$column}` {$direction}";
	}

	/**
	 * Decode a JSON column into an array.
	 *
	 * @param mixed $value Raw column value.
	 * @return array<string, mixed>
	 */
	protected function json( mixed $value ): array {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Encode an array for a JSON column.
	 *
	 * @param array<array-key, mixed> $value Value.
	 * @return string|null
	 */
	protected function encodeJson( array $value ): ?string {
		if ( array() === $value ) {
			return null;
		}

		$encoded = wp_json_encode( $value );

		return false === $encoded ? null : $encoded;
	}

	/**
	 * Current UTC time as a MySQL DATETIME.
	 *
	 * @return string
	 */
	protected function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
