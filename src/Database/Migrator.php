<?php
/**
 * Versioned migration runner.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database;

use Hiveclerk\Core\Support\LockInterface;
use Throwable;

/**
 * Applies and reverses schema migrations in version order.
 */
final class Migrator {

	private const VERSION_OPTION = 'hiveclerk_db_version';
	private const LOCK_NAME      = 'migrate';

	/**
	 * Construct.
	 *
	 * @param LockInterface $locks Mutual exclusion for a run.
	 */
	public function __construct( private readonly LockInterface $locks = new NamedLock() ) {
	}

	/**
	 * Registered migrations, unsorted.
	 *
	 * @var array<int, Migration>
	 */
	private array $migrations = array();

	/**
	 * Messages describing what the last run did.
	 *
	 * @var array<int, string>
	 */
	private array $log = array();

	/**
	 * Register a migration.
	 *
	 * @param Migration $migration Migration.
	 * @return void
	 *
	 * @throws \InvalidArgumentException When two migrations share a version.
	 */
	public function add( Migration $migration ): void {
		$version = $migration->version();

		if ( isset( $this->migrations[ $version ] ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Migration version %d is claimed by both %s and %s.',
					$version,
					$this->migrations[ $version ]::class,
					$migration::class
				)
			);
		}

		$this->migrations[ $version ] = $migration;
	}

	/**
	 * Version currently applied to this database.
	 *
	 * @return int
	 */
	public function currentVersion(): int {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * Highest version available in code.
	 *
	 * @return int
	 */
	public function latestVersion(): int {
		if ( array() === $this->migrations ) {
			return 0;
		}

		return max( array_keys( $this->migrations ) );
	}

	/**
	 * Whether the database is behind the code.
	 *
	 * @return bool
	 */
	public function needsMigration(): bool {
		return $this->currentVersion() < $this->latestVersion();
	}

	/**
	 * Apply every pending migration.
	 *
	 * A concurrent request must not run the same migration twice, so the run
	 * is guarded by a lock. If a migration throws, the version is left at the
	 * last one that succeeded: the next request retries from there rather
	 * than skipping the broken step.
	 *
	 * ## The lock has to be one
	 *
	 * This read a transient and then wrote one, which is not a lock: two
	 * requests arriving together both read nothing and both proceed. It was
	 * mostly survivable because the DDL is written to be re-runnable —
	 * `CREATE TABLE IF NOT EXISTS`, guarded `ADD INDEX` — but "mostly" is
	 * doing a lot of work in that sentence, and the guarantee only held for
	 * as long as every future migration remembered to be idempotent.
	 *
	 * {@see NamedLock} is a MySQL advisory lock: exclusion is decided by the
	 * server, and it is scoped to the connection, so a process killed
	 * mid-migration releases it by disconnecting rather than blocking the
	 * schema until some expiry guesses that it died.
	 *
	 * @return bool True when the database is now at the latest version.
	 */
	public function migrate(): bool {
		if ( ! $this->needsMigration() ) {
			return true;
		}

		if ( ! $this->claimLock() ) {
			return false;
		}

		$pending = $this->pending();
		$applied = $this->currentVersion();

		try {
			foreach ( $pending as $version => $migration ) {
				$migration->up();

				$applied = $version;
				update_option( self::VERSION_OPTION, $applied, false );

				$this->log[] = sprintf( '#%d %s', $version, $migration->description() );
			}
		} catch ( Throwable $e ) {
			$this->log[] = sprintf( 'Failed after #%d: %s', $applied, $e->getMessage() );
			$this->releaseLock();

			do_action( 'hiveclerk/migration/failed', $applied, $e );

			return false;
		}

		$this->releaseLock();
		delete_option( 'hiveclerk_needs_migration' );

		do_action( 'hiveclerk/migration/completed', $applied );

		return true;
	}

	/**
	 * Roll back to a target version.
	 *
	 * @param int $target Version to return to. 0 removes everything.
	 * @return bool
	 */
	public function rollback( int $target = 0 ): bool {
		$current = $this->currentVersion();

		if ( $current <= $target ) {
			return true;
		}

		$versions = array_keys( $this->migrations );
		rsort( $versions );

		try {
			foreach ( $versions as $version ) {
				if ( $version <= $target || $version > $current ) {
					continue;
				}

				$this->migrations[ $version ]->down();

				update_option( self::VERSION_OPTION, $version - 1, false );

				$this->log[] = sprintf( 'Reverted #%d', $version );
			}
		} catch ( Throwable $e ) {
			$this->log[] = sprintf( 'Rollback failed: %s', $e->getMessage() );

			return false;
		}

		update_option( self::VERSION_OPTION, $target, false );

		return true;
	}

	/**
	 * Take the migration lock, or report that somebody else holds it.
	 *
	 * @return bool
	 */
	private function claimLock(): bool {
		return $this->locks->acquire( self::LOCK_NAME );
	}

	/**
	 * Release the migration lock.
	 *
	 * @return void
	 */
	private function releaseLock(): void {
		$this->locks->release( self::LOCK_NAME );
	}

	/**
	 * Migrations not yet applied, in ascending version order.
	 *
	 * @return array<int, Migration>
	 */
	public function pending(): array {
		$current = $this->currentVersion();
		$pending = array();

		foreach ( $this->migrations as $version => $migration ) {
			if ( $version > $current ) {
				$pending[ $version ] = $migration;
			}
		}

		ksort( $pending );

		return $pending;
	}

	/**
	 * Which tables exist right now.
	 *
	 * @return array<string, bool>
	 */
	public function tableStatus(): array {
		global $wpdb;

		$status = array();

		foreach ( Schema::all() as $table ) {
			$name = Schema::table( $table );

			$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $name )
			);

			$status[ $table ] = ( $name === $found );
		}

		return $status;
	}

	/**
	 * What the last run did.
	 *
	 * @return array<int, string>
	 */
	public function log(): array {
		return $this->log;
	}
}
