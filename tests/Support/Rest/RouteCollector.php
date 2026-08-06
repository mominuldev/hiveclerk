<?php
/**
 * Captures what a controller registers, without WordPress.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Rest;

use Hiveclerk\Api\AbstractController;
use ReflectionClass;

/**
 * Every route the plugin declares, as data.
 *
 * `tools/verify-routes.php` already asserts these routes are gated, and
 * it does it against a booted WordPress with the real REST server — which
 * is the right place for that check and the wrong place for the only
 * copy of it. It needs a live install, so it cannot run in `composer
 * check`, which means the gating assertion is absent from every run a
 * developer actually does before pushing.
 *
 * Controllers are built with `newInstanceWithoutConstructor()`. That
 * looks like a cheat and is the observation that makes this possible:
 * `registerRoutes()` describes routes, it does not serve them. The
 * permission callbacks and handlers it declares are closures, and none of
 * them is invoked at registration time, so the repositories and services
 * a controller would normally be given are not needed to find out what it
 * declares. Building the real dependency graph for twenty-six
 * controllers would be a fixture larger than the thing under test.
 *
 * Verified rather than assumed: this produces the same 98 routes the
 * live check counts.
 */
final class RouteCollector {

	/**
	 * The collector currently walking a controller, and which one.
	 *
	 * A static rather than a global: `register_rest_route()` is a free
	 * function, so the stub that captures it needs somewhere to hand the
	 * registration back to, and this is the narrowest place that is.
	 *
	 * @var array{0: self, 1: string}|null
	 */
	public static ?array $active = null;

	/**
	 * Registered routes, flattened to one entry per method group.
	 *
	 * @var array<int, array{controller: string, namespace: string, route: string, args: array<string, mixed>}>
	 */
	public array $routes = array();

	/**
	 * Controllers that could not be inspected, and why.
	 *
	 * @var array<string, string>
	 */
	public array $skipped = array();

	/**
	 * Walk every controller in the codebase and record its routes.
	 *
	 * @param string $root Plugin root directory.
	 * @return self
	 */
	public static function collect( string $root ): self {
		$collector = new self();

		foreach ( self::controllerClasses( $root ) as $class ) {
			try {
				$controller = ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();

				if ( ! $controller instanceof AbstractController ) {
					continue;
				}

				self::$active = array( $collector, $class );

				$controller->registerRoutes();
			} catch ( \Throwable $e ) {
				$collector->skipped[ $class ] = $e->getMessage();
			} finally {
				self::$active = null;
			}
		}

		return $collector;
	}

	/**
	 * Record one registration.
	 *
	 * WordPress accepts either a single route definition or a list of
	 * them, so a route serving GET and DELETE arrives as two entries under
	 * one path. Flattened here, because every assertion downstream is
	 * about one method group.
	 *
	 * @param string       $controller    Declaring class.
	 * @param string       $restNamespace REST namespace.
	 * @param string       $route      Route pattern.
	 * @param array<mixed> $args       Route definition, or a list of them.
	 * @return void
	 */
	public function record( string $controller, string $restNamespace, string $route, array $args ): void {
		$definitions = isset( $args['callback'] ) || isset( $args['methods'] )
			? array( $args )
			: $args;

		foreach ( $definitions as $definition ) {
			if ( is_array( $definition ) ) {
				$this->routes[] = array(
					'controller' => $controller,
					'namespace'  => $restNamespace,
					'route'      => $route,
					'args'       => $definition,
				);
			}
		}
	}

	/**
	 * Every concrete controller class.
	 *
	 * @param string $root Plugin root.
	 * @return array<int, class-string>
	 */
	private static function controllerClasses( string $root ): array {
		$classes = array();

		$files = new \RegexIterator(
			new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root . '/src' )
			),
			'/Controller\.php$/'
		);

		foreach ( $files as $file ) {
			$path = (string) $file;

			if ( str_ends_with( $path, 'AbstractController.php' ) ) {
				continue;
			}

			$source = (string) file_get_contents( $path );

			if ( ! preg_match( '/^namespace\s+([^;]+);/m', $source, $declared ) ) {
				continue;
			}

			if ( ! preg_match( '/^final class\s+(\w+)/m', $source, $matched ) ) {
				continue;
			}

			$name = trim( $declared[1] ) . '\\' . $matched[1];

			if ( class_exists( $name ) ) {
				$classes[] = $name;
			}
		}

		sort( $classes );

		return $classes;
	}
}
