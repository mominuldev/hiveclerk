<?php
/**
 * Module registry.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Module;

use Hiveclerk\Core\Container\Container;

/**
 * Registers and boots feature modules.
 */
final class ModuleRegistry {

	/**
	 * Modules keyed by id.
	 *
	 * @var array<string, ModuleInterface>
	 */
	private array $modules = array();

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Construct.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( private readonly Container $container ) {
	}

	/**
	 * Add a module. Unavailable modules are skipped, never fatal.
	 *
	 * @param ModuleInterface $module Module.
	 * @return void
	 */
	public function add( ModuleInterface $module ): void {
		if ( ! $module->isAvailable() ) {
			return;
		}

		$this->modules[ $module::id() ] = $module;
	}

	/**
	 * Register every module's services, then boot them all.
	 *
	 * Registration completes for all modules before any module boots, so a
	 * module may depend on another module's services without caring about
	 * the order they were added in.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		foreach ( $this->modules as $module ) {
			$module->register( $this->container );
		}

		foreach ( $this->modules as $module ) {
			$module->boot();
		}

		$this->booted = true;
	}

	/**
	 * Whether a module is registered and available.
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->modules[ $id ] );
	}

	/**
	 * All registered modules.
	 *
	 * @return array<string, ModuleInterface>
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * Every migration across every registered module.
	 *
	 * @return array<int, class-string>
	 */
	public function migrations(): array {
		$migrations = array();

		foreach ( $this->modules as $module ) {
			foreach ( $module->migrations() as $migration ) {
				$migrations[] = $migration;
			}
		}

		return $migrations;
	}

	/**
	 * Every capability across every registered module.
	 *
	 * @return array<int, string>
	 */
	public function capabilities(): array {
		$capabilities = array();

		foreach ( $this->modules as $module ) {
			foreach ( $module->capabilities() as $capability ) {
				$capabilities[] = $capability;
			}
		}

		return array_values( array_unique( $capabilities ) );
	}
}
