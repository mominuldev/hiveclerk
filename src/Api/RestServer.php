<?php
/**
 * REST route registration.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Api;

/**
 * Collects controllers and registers their routes on rest_api_init.
 */
final class RestServer {

	/**
	 * Registered controllers, keyed by class so a repeated add() is a
	 * replacement rather than a duplicate.
	 *
	 * @var array<class-string<AbstractController>, AbstractController>
	 */
	private array $controllers = array();

	/**
	 * Whether the registration pass has already run this request.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Add a controller.
	 *
	 * Deduplicated by class. `rest_api_init` fires more than once in a
	 * single request — `rest_get_server()` fires it again, and so does
	 * anything that asks for the server early — and the modules re-run
	 * their listeners on each firing. Appending blindly registered every
	 * route three times.
	 *
	 * Duplicate routes are not merely untidy. `register_rest_route()`
	 * appends a handler rather than replacing one, and a public route's
	 * rate limit is consumed once per handler WordPress walks — so the
	 * ceiling an operator configured was silently a third of what it said.
	 *
	 * @param AbstractController $controller Controller.
	 * @return void
	 */
	public function add( AbstractController $controller ): void {
		$this->controllers[ $controller::class ] = $controller;
	}

	/**
	 * Attach the registration hook.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Register every controller's routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		if ( $this->registered ) {
			// WordPress keeps its route table for the whole request, so a
			// second pass adds nothing and costs a duplicate handler on
			// every route.
			return;
		}

		$this->registered = true;

		/**
		 * Register additional controllers.
		 *
		 * Fired before the loop, not after it. A listener calling add()
		 * once the loop has finished appends a controller whose
		 * registerRoutes() is then never called: the routes are silently
		 * absent and the only symptom is a 404 from an endpoint whose code
		 * plainly exists.
		 *
		 * This was invisible until the knowledge module became the first
		 * real user of the hook, and it was invisible under the route
		 * checker too — rest_get_server() fires rest_api_init a second
		 * time, so a controller added on the first pass was registered on
		 * the second, and only a live HTTP request showed the gap.
		 *
		 * @param RestServer $server This server.
		 */
		do_action( 'hiveclerk/rest/register', $this );

		foreach ( $this->controllers as $controller ) {
			$controller->registerRoutes();
		}
	}

	/**
	 * Controllers registered so far.
	 *
	 * @return array<int, AbstractController>
	 */
	public function controllers(): array {
		return array_values( $this->controllers );
	}
}
