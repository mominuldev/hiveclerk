<?php
/**
 * Migration contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database;

/**
 * A single, idempotent schema change.
 *
 * dbDelta() is not used. It cannot drop columns, rename, or alter indexes
 * reliably, and it silently mangles FULLTEXT and VARBINARY definitions —
 * both of which the retrieval tables depend on.
 */
abstract class Migration {

	/**
	 * Monotonic version. Applied in ascending order.
	 *
	 * @return int
	 */
	abstract public function version(): int;

	/**
	 * Human-readable description, written to the migration log.
	 *
	 * @return string
	 */
	abstract public function description(): string;

	/**
	 * Apply the change. Must be safe to run twice.
	 *
	 * @return void
	 */
	abstract public function up(): void;

	/**
	 * Reverse the change.
	 *
	 * @return void
	 */
	abstract public function down(): void;

	/**
	 * Run a statement.
	 *
	 * @param string $sql Statement with no caller-supplied identifiers.
	 * @return void
	 */
	protected function run( string $sql ): void {
		global $wpdb;

		// DDL cannot be parameterised. Every identifier in migration SQL is a
		// compile-time constant from Schema, so no input reaches this call.
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	/**
	 * Charset and collation matching the host WordPress install.
	 *
	 * @return string
	 */
	protected function charset(): string {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();

		return '' !== $collate ? $collate : 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
	}

	/**
	 * Whether a column already exists.
	 *
	 * MySQL 8 has no `ADD COLUMN IF NOT EXISTS` — that spelling is
	 * MariaDB's, and a migration written with it applies cleanly on half
	 * the hosting landscape and hard-fails on the other half. Asking
	 * first is the only form of idempotence both engines share.
	 *
	 * @param string $table  Schema constant.
	 * @param string $column Column name.
	 * @return bool
	 */
	protected function hasColumn( string $table, string $column ): bool {
		global $wpdb;

		$name = Schema::table( $table );

		// LIKE takes a value, not an identifier, so it is prepared. The
		// table name is a Schema constant and cannot be parameterised.
		$sql = $wpdb->prepare( "SHOW COLUMNS FROM `{$name}` LIKE %s", $column ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_string( $sql ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		return null !== $wpdb->get_var( $sql );
	}

	/**
	 * Whether an index already exists.
	 *
	 * @param string $table Schema constant.
	 * @param string $index Index name.
	 * @return bool
	 */
	protected function hasIndex( string $table, string $index ): bool {
		global $wpdb;

		$name = Schema::table( $table );

		$sql = $wpdb->prepare( "SHOW INDEX FROM `{$name}` WHERE Key_name = %s", $index ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_string( $sql ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		return null !== $wpdb->get_var( $sql );
	}

	/**
	 * Drop a table.
	 *
	 * @param string $table Schema constant.
	 * @return void
	 */
	protected function drop( string $table ): void {
		$name = Schema::table( $table );

		$this->run( "DROP TABLE IF EXISTS `{$name}`" );
	}
}
