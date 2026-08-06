<?php
/**
 * The contract every REST route is held to.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Api\AbstractController;
use Hiveclerk\Tests\Support\Rest\RouteCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Nineteen controllers had no unit tests at all.
 *
 * What was untested is not the handlers — those need repositories and a
 * database, and the integration suite is where they belong — but the
 * declarations: which capability guards a route, whether a string
 * parameter is sanitised, whether a closed set is validated. That is the
 * layer the chunk-configuration cost-exhaustion vector lived in, where an
 * authenticated caller could set a chunk target of 1 over REST and turn
 * one re-index into an embedding bill.
 *
 * `tools/verify-routes.php` covers the gating already, and covers it
 * well, against a booted WordPress. It also needs a booted WordPress,
 * which is why it is not in `composer check` and why a developer can
 * ship an ungated route without ever running the thing that would catch
 * it. This is the same assertion where it costs nothing to run.
 *
 * Assertions are made against every route the codebase declares rather
 * than a list, so a controller added tomorrow is covered the moment it
 * exists.
 *
 * @internal
 */
#[CoversClass( AbstractController::class )]
final class RouteContractTest extends TestCase {

	/**
	 * Collected routes, gathered once for the class.
	 *
	 * @var RouteCollector|null
	 */
	private static ?RouteCollector $collected = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'register_rest_route' )->alias(
			static function ( string $restNamespace, string $route, array $args = array() ): bool {
				if ( null !== RouteCollector::$active ) {
					[ $collector, $class ] = RouteCollector::$active;
					$collector->record( $class, $restNamespace, $route, $args );
				}

				return true;
			}
		);

		// Registration only builds closures; these exist because a few
		// controllers compute a default or a label while declaring args.
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\when( 'rest_url' )->alias( static fn( string $path = '' ): string => 'https://example.test/' . $path );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'rest_validate_request_arg' )->justReturn( true );
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Every route in the plugin.
	 *
	 * @return array<int, array{controller: string, namespace: string, route: string, args: array<string, mixed>}>
	 */
	private function routes(): array {
		if ( null === self::$collected ) {
			self::$collected = RouteCollector::collect( dirname( __DIR__, 3 ) );
		}

		return self::$collected->routes;
	}

	/**
	 * A broken collector would make every assertion below vacuous.
	 */
	public function testEveryControllerWasInspected(): void {
		$this->routes();

		self::assertSame(
			array(),
			self::$collected?->skipped ?? array(),
			'a controller could not be inspected, so its routes are unchecked'
		);

		self::assertGreaterThanOrEqual(
			90,
			count( $this->routes() ),
			'far fewer routes than the codebase declares — collection is broken'
		);
	}

	/**
	 * SEC-04, without needing a WordPress to assert it.
	 */
	public function testEveryRouteHasARealPermissionCallback(): void {
		foreach ( $this->routes() as $route ) {
			$where    = $route['controller'] . ' ' . $route['route'];
			$callback = $route['args']['permission_callback'] ?? null;

			self::assertNotNull( $callback, $where . ' has no permission_callback' );
			self::assertIsCallable( $callback, $where . ' has a permission_callback that is not callable' );
			self::assertNotSame(
				'__return_true',
				$callback,
				$where . ' is open to the world'
			);
		}
	}

	/**
	 * A callback that exists is not the same as a callback that refuses.
	 *
	 * This is the assertion a static check cannot make: every gate is
	 * invoked against a signed-out caller and against one whose account
	 * lacks the capability, and both must be turned away. A permission
	 * callback that is non-trivial and always returns true would satisfy
	 * every other test here and gate nothing.
	 */
	public function testEveryAdminGateRefusesTheSignedOutAndTheUnprivileged(): void {
		$routes = $this->routes();
		$gates  = 0;

		foreach ( $routes as $route ) {
			// Public widget routes authenticate with a session token, not a
			// capability, and have no signed-in user to reason about.
			if ( ! str_starts_with( $route['route'], '/admin' ) ) {
				continue;
			}

			$callback = $route['args']['permission_callback'];
			$where    = $route['controller'] . ' ' . $route['route'];

			Functions\when( 'is_user_logged_in' )->justReturn( false );
			Functions\when( 'current_user_can' )->justReturn( true );

			$anonymous = $callback( null );

			self::assertInstanceOf( WP_Error::class, $anonymous, $where . ' allowed a signed-out caller' );
			self::assertSame( 401, $anonymous->get_error_data()['status'] ?? 0, $where . ' did not answer 401' );

			Functions\when( 'is_user_logged_in' )->justReturn( true );
			Functions\when( 'current_user_can' )->justReturn( false );

			$unprivileged = $callback( null );

			self::assertInstanceOf( WP_Error::class, $unprivileged, $where . ' allowed a caller without the capability' );
			self::assertSame( 403, $unprivileged->get_error_data()['status'] ?? 0, $where . ' did not answer 403' );

			++$gates;
		}

		self::assertGreaterThan( 50, $gates, 'suspiciously few admin routes were exercised' );
	}

	/**
	 * String parameters cleaned somewhere other than the route definition.
	 *
	 * Every entry was read before it was added, and each records where the
	 * cleaning actually happens. The list is asserted to be *exact*: an
	 * entry that stops being needed fails the test, so it cannot quietly
	 * become a list of things nobody re-checked.
	 *
	 * Two different reasons appear here and they are worth keeping apart.
	 *
	 * `api_key` is not sanitised anywhere, on purpose.
	 * `sanitize_text_field()` on a malformed key produces a quietly
	 * corrupted one that fails later against the provider, pointing the
	 * operator at Anthropic when the fault is a stray line break in our
	 * own form. It is validated against a pattern and refused with 422.
	 *
	 * The rest are multi-line, and `sanitize_text_field()` — the callback
	 * a route definition would reach for — flattens them. They go through
	 * `sanitize_textarea_field()` or `wp_kses_post()` in the handler, at
	 * the point where the right one is known.
	 *
	 * @var array<string, string>
	 */
	private const CLEANED_IN_HANDLER = array(
		'Hiveclerk\Api\Controllers\ProvidersController::api_key'          =>
			'validated against KEY_PATTERN and refused with 422; sanitising would corrupt it',
		'Hiveclerk\Modules\Chat\Http\ConversationController::message'     => 'sanitize_textarea_field() in reply()',
		'Hiveclerk\Modules\Chat\Http\ConversationController::note'        => 'sanitize_textarea_field() in note()',
		'Hiveclerk\Modules\Agents\Http\AgentController::greeting'         => 'sanitize_textarea_field() in the update path',
		'Hiveclerk\Modules\Agents\Http\AgentController::fallback_message' => 'sanitize_textarea_field() in the update path',
		'Hiveclerk\Modules\Agents\Http\AgentController::instructions'     => 'sanitize_textarea_field() in the update path',
		'Hiveclerk\Modules\KnowledgeBase\Http\SourceController::csv'      =>
			'size-capped then parsed by the CSV importer; sanitising would destroy the delimiters',
		'Hiveclerk\Modules\Email\Http\SequenceController::subject'        => 'sanitize_text_field() in the step update',
		'Hiveclerk\Modules\Email\Http\SequenceController::body_html'      => 'wp_kses_post() in the step update',
		'Hiveclerk\Modules\Email\Http\SequenceController::body_text'      => 'sanitize_textarea_field() in the step update',
	);

	/**
	 * Sanitising is not validating, but an unsanitised string is neither.
	 *
	 * Every string parameter reaching a handler has been through one of
	 * the two, or is named above with the reason it is not. A `type:
	 * string` arg with neither is raw request input handed to application
	 * code, which is the shape the chunk-configuration cost-exhaustion
	 * vector had: values nothing would have accepted on a form, reaching
	 * a value object that clamped them and carried on.
	 */
	public function testEveryStringParameterIsSanitisedOrDeliberatelyNot(): void {
		$checked  = 0;
		$exempted = array();

		foreach ( $this->routes() as $route ) {
			foreach ( $route['args']['args'] ?? array() as $name => $definition ) {
				if ( ! is_array( $definition ) || 'string' !== ( $definition['type'] ?? null ) ) {
					continue;
				}

				++$checked;

				if ( isset( $definition['sanitize_callback'] ) || isset( $definition['validate_callback'] ) ) {
					continue;
				}

				$key = $route['controller'] . '::' . $name;

				self::assertArrayHasKey(
					$key,
					self::CLEANED_IN_HANDLER,
					sprintf(
						'%s %s: the "%s" parameter is a raw string with no sanitize_callback and no '
							. 'validate_callback. If its handler cleans it, say so in CLEANED_IN_HANDLER '
							. 'with where; if not, this is unguarded request input.',
						$route['controller'],
						$route['route'],
						$name
					)
				);

				$exempted[ $key ] = true;
			}
		}

		self::assertGreaterThan( 20, $checked, 'no string parameters were examined' );

		// An exemption nobody needs any more is an exemption nobody has
		// re-read. Left in place it silently widens next time the
		// parameter it names comes back.
		self::assertSame(
			array(),
			array_diff( array_keys( self::CLEANED_IN_HANDLER ), array_keys( $exempted ) ),
			'these parameters are now sanitised at the boundary and no longer need an exemption'
		);
	}

	/**
	 * A closed set has to be enforced somewhere.
	 *
	 * WordPress does not check `enum` unless a validate_callback asks it
	 * to, so an arg that declares one and stops there is documentation
	 * rather than a constraint.
	 */
	public function testEveryEnumParameterIsValidated(): void {
		$checked = 0;

		foreach ( $this->routes() as $route ) {
			foreach ( $route['args']['args'] ?? array() as $name => $definition ) {
				if ( ! is_array( $definition ) || ! isset( $definition['enum'] ) ) {
					continue;
				}

				++$checked;

				self::assertArrayHasKey(
					'validate_callback',
					$definition,
					sprintf(
						'%s %s: the "%s" parameter declares an enum that nothing enforces',
						$route['controller'],
						$route['route'],
						$name
					)
				);
			}
		}

		self::assertGreaterThan( 0, $checked, 'no enum parameters were examined' );
	}

	/**
	 * Everything the plugin serves lives under one versioned namespace.
	 */
	public function testEveryRouteIsRegisteredUnderTheProductNamespace(): void {
		foreach ( $this->routes() as $route ) {
			self::assertSame(
				AbstractController::NAMESPACE,
				$route['namespace'],
				$route['controller'] . ' ' . $route['route'] . ' is outside hiveclerk/v1'
			);
		}
	}

	/**
	 * A route with no methods answers nothing; one with no callback fatals.
	 */
	public function testEveryRouteDeclaresItsMethodsAndAHandler(): void {
		foreach ( $this->routes() as $route ) {
			$where = $route['controller'] . ' ' . $route['route'];

			self::assertArrayHasKey( 'methods', $route['args'], $where . ' declares no methods' );
			self::assertArrayHasKey( 'callback', $route['args'], $where . ' declares no callback' );
			self::assertIsCallable( $route['args']['callback'], $where . ' has an uncallable handler' );
		}
	}

	/**
	 * Route patterns interpolate identifiers, so a malformed one is a
	 * route that silently never matches.
	 */
	public function testEveryRoutePatternIsWellFormed(): void {
		foreach ( $this->routes() as $route ) {
			$where = $route['controller'] . ' ' . $route['route'];

			self::assertStringStartsWith( '/', $route['route'], $where . ' is not rooted' );
			self::assertStringNotContainsString( '//', $route['route'], $where . ' has an empty segment' );
			self::assertSame(
				substr_count( $route['route'], '(' ),
				substr_count( $route['route'], ')' ),
				$where . ' has unbalanced parentheses in its pattern'
			);
		}
	}
}
