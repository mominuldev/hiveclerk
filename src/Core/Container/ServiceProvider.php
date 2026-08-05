<?php
/**
 * Service provider contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Container;

/**
 * Binds a related group of services into the container.
 */
abstract class ServiceProvider {

	/**
	 * Bind services.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	abstract public function register( Container $container ): void;
}
