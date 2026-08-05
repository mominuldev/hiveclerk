<?php
/**
 * Module contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Module;

use Hiveclerk\Core\Container\Container;

/**
 * A feature module owning a full vertical slice.
 *
 * Modules never call each other's services. They communicate through the
 * event bus, which is what lets a module be added or removed without
 * touching the modules around it.
 */
interface ModuleInterface {

	/**
	 * Stable machine identifier.
	 *
	 * @return string
	 */
	public static function id(): string;

	/**
	 * Bind this module's services into the container.
	 *
	 * Runs for every module before any module boots, so cross-module
	 * dependencies resolve regardless of registration order.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void;

	/**
	 * Attach hooks, routes and listeners.
	 *
	 * @return void
	 */
	public function boot(): void;

	/**
	 * Migration class names owned by this module.
	 *
	 * @return array<int, class-string>
	 */
	public function migrations(): array;

	/**
	 * Capabilities this module requires.
	 *
	 * @return array<int, string>
	 */
	public function capabilities(): array;

	/**
	 * Whether the module can run: licence tier, dependencies, environment.
	 *
	 * A module that returns false is skipped. The product degrades; it
	 * never fatals.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool;
}
