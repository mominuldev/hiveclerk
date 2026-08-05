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
