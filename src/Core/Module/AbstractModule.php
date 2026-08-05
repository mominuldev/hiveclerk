<?php
/**
 * Module base class.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Module;

use Hiveclerk\Core\Container\Container;

/**
 * Sensible defaults so a module only declares what it actually uses.
 */
abstract class AbstractModule implements ModuleInterface {

	/**
	 * Container, available to subclasses after registration.
	 *
	 * @var Container
	 */
	protected Container $container;

	/**
	 * Bind services. Override as needed.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$this->container = $container;
	}

	/**
	 * Attach hooks. Override as needed.
	 *
	 * @return void
	 */
	public function boot(): void {
	}

	/**
	 * Migrations owned by this module.
	 *
	 * @return array<int, class-string>
	 */
	public function migrations(): array {
		return array();
	}

	/**
	 * Capabilities required by this module.
	 *
	 * @return array<int, string>
	 */
	public function capabilities(): array {
		return array();
	}

	/**
	 * Modules are available by default.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		return true;
	}
}
