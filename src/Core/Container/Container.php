<?php
/**
 * PSR-11 container.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Container;

use Psr\Container\ContainerInterface;

/**
 * A small PSR-11 container.
 *
 * Deliberately hand-written rather than pulled from a package. Duplicated
 * Composer dependencies across plugins are the most common cause of fatal
 * errors in the WordPress ecosystem, and a container is small enough that
 * owning it costs less than the conflict risk.
 */
final class Container implements ContainerInterface {

	/**
	 * Factories keyed by identifier.
	 *
	 * @var array<string, callable(Container): mixed>
	 */
	private array $factories = array();

	/**
	 * Identifiers that resolve once and cache.
	 *
	 * @var array<string, true>
	 */
	private array $shared = array();

	/**
	 * Resolved shared instances.
	 *
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * Identifiers currently being resolved, used to detect cycles.
	 *
	 * @var array<string, true>
	 */
	private array $resolving = array();

	/**
	 * Register a factory that returns a new instance on every call.
	 *
	 * @param string                     $id      Identifier, usually a class name.
	 * @param callable(Container): mixed $factory Factory closure.
	 * @return void
	 */
	public function bind( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->shared[ $id ], $this->instances[ $id ] );
	}

	/**
	 * Register a factory that resolves once and caches.
	 *
	 * @param string                     $id      Identifier, usually a class name.
	 * @param callable(Container): mixed $factory Factory closure.
	 * @return void
	 */
	public function singleton( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		$this->shared[ $id ]    = true;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Store an already-built instance.
	 *
	 * @param string $id       Identifier.
	 * @param mixed  $instance Instance.
	 * @return void
	 */
	public function instance( string $id, mixed $instance ): void {
		$this->instances[ $id ] = $instance;
		$this->shared[ $id ]    = true;
	}

	/**
	 * Alias one identifier to another.
	 *
	 * @param string $alias  New identifier, usually an interface.
	 * @param string $target Existing identifier, usually a concrete class.
	 * @return void
	 */
	public function alias( string $alias, string $target ): void {
		$this->singleton( $alias, static fn( Container $c ): mixed => $c->get( $target ) );
	}

	/**
	 * Resolve an identifier.
	 *
	 * @param string $id Identifier.
	 * @return mixed
	 *
	 * @throws NotFoundException When the identifier is not registered.
	 * @throws ContainerException When resolution would recurse forever.
	 */
	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new NotFoundException(
				sprintf( 'Nothing is registered in the container for "%s".', $id )
			);
		}

		if ( isset( $this->resolving[ $id ] ) ) {
			throw new ContainerException(
				sprintf(
					'Circular dependency while resolving "%s". Chain: %s.',
					$id,
					implode( ' -> ', array_keys( $this->resolving ) )
				)
			);
		}

		$this->resolving[ $id ] = true;

		try {
			$object = ( $this->factories[ $id ] )( $this );
		} finally {
			unset( $this->resolving[ $id ] );
		}

		if ( isset( $this->shared[ $id ] ) ) {
			$this->instances[ $id ] = $object;
		}

		return $object;
	}

	/**
	 * Whether an identifier is registered.
	 *
	 * @param string $id Identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || array_key_exists( $id, $this->instances );
	}

	/**
	 * Register a service provider.
	 *
	 * @param ServiceProvider $provider Provider.
	 * @return void
	 */
	public function register( ServiceProvider $provider ): void {
		$provider->register( $this );
	}
}
