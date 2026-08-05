<?php
/**
 * What the database server actually is.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database;

use wpdb;

/**
 * Reports the database server's own version and character set.
 *
 * A separate class rather than three methods on `Migrator`, because this
 * has nothing to do with migrations and everything to do with the health
 * screen. It lives in `Database/` for one reason: that is the only layer
 * allowed to hold `$wpdb`, and the alternative would have been the health
 * endpoint reaching for a global.
 *
 * The version matters more than it looks. The retrieval path depends on a
 * FULLTEXT index, and MySQL below 5.6 and MariaDB below 10.0 do not offer
 * one on InnoDB — a site on either would fall back to a slower scan and
 * nothing would say why.
 */
final class ServerInfo {

	/**
	 * WordPress database handle.
	 */
	private wpdb $db;

	/**
	 * Construct.
	 */
	public function __construct() {
		global $wpdb;

		$this->db = $wpdb;
	}

	/**
	 * The server's reported version string.
	 *
	 * @return string
	 */
	public function version(): string {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$version = $this->db->get_var( 'SELECT VERSION()' );

		return is_string( $version ) ? $version : '';
	}

	/**
	 * Whether the server is MariaDB rather than MySQL.
	 *
	 * They report separate version numbers for the same features, so a
	 * screen that prints "10.11" without saying which is inviting the
	 * reader to conclude their MySQL is eight years out of date.
	 *
	 * @return bool
	 */
	public function isMariaDb(): bool {
		return str_contains( strtolower( $this->version() ), 'mariadb' );
	}

	/**
	 * The character set the tables were created with.
	 *
	 * @return string
	 */
	public function charset(): string {
		return (string) $this->db->charset;
	}

	/**
	 * The collation the tables were created with.
	 *
	 * @return string
	 */
	public function collation(): string {
		return (string) $this->db->collate;
	}
}
