<?php
/**
 * Base for tests that go through the real REST server.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Integration;

use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * Drives routes the way WordPress drives them.
 *
 * The unit suite asserts what controllers *declare* — which capability
 * guards a route, whether a parameter is sanitised. What it cannot reach
 * is what happens when a request actually arrives: whether the envelope
 * has the shape every client is written against, whether an unknown uuid
 * is a 404 rather than a fatal, whether the capability a route names is
 * the capability WordPress enforces.
 *
 * `rest_do_request()` is the whole point. It runs the real dispatcher, so
 * argument defaults, type coercion, `sanitize_callback` and
 * `validate_callback` all happen exactly as they do in production — the
 * parts a stub request in the unit suite deliberately does not imitate.
 *
 * ## These run against a real site, so they clean up after themselves
 *
 * A developer runs this against their development install, which has
 * their own clerks and conversations in it. Every fixture is created
 * through the API under test, recorded, and removed in tear-down; nothing
 * here issues a destructive call against a record it did not make. A test
 * suite that eats the data you were using to test with is a suite people
 * stop running.
 */
abstract class RestTestCase extends WordPressTestCase {

	/**
	 * Prefix every fixture carries, so a leaked one is identifiable.
	 */
	protected const FIXTURE_PREFIX = 'zz-resttest-';

	/**
	 * Users created for a test, removed afterwards.
	 *
	 * @var array<int, int>
	 */
	private array $users = array();

	/**
	 * Cleanup callbacks, run in reverse order.
	 *
	 * @var array<int, callable>
	 */
	private array $cleanup = array();

	/**
	 * Whoever was current before the test took over.
	 *
	 * @var int
	 */
	private int $previousUser = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->previousUser = get_current_user_id();

		// The REST server is built lazily and caches its routes. Asking for
		// it here means `rest_api_init` has fired before the first request,
		// which is what a web request would have done.
		rest_get_server();

		// wp-load stops short of the admin includes, and user creation and
		// deletion live there. Required here rather than in the bootstrap
		// so a suite that never makes a user never loads them.
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$this->removeStrandedFixtures();
	}

	protected function tearDown(): void {
		foreach ( array_reverse( $this->cleanup ) as $task ) {
			$task();
		}

		$this->cleanup = array();

		foreach ( $this->users as $id ) {
			wp_delete_user( $id );
		}

		$this->users = array();

		wp_set_current_user( $this->previousUser );

		parent::tearDown();
	}

	/**
	 * Register work to undo when the test finishes.
	 *
	 * @param callable $task Undo.
	 * @return void
	 */
	protected function afterwards( callable $task ): void {
		$this->cleanup[] = $task;
	}

	/**
	 * Remove fixture users a previous run failed to clean up.
	 *
	 * Tear-down handles the ordinary case; this handles the one that
	 * happened. An early version of this class called `wp_delete_user()`
	 * without loading the admin include it lives in, so tear-down threw
	 * and left two accounts on the development site — invisible, because
	 * the run reported a different error.
	 *
	 * A fixture prefix nobody would type by hand makes the sweep safe:
	 * it can only match accounts this class created.
	 *
	 * @return void
	 */
	private function removeStrandedFixtures(): void {
		$stranded = get_users(
			array(
				'search'         => self::FIXTURE_PREFIX . '*',
				'search_columns' => array( 'user_login' ),
				'number'         => 50,
				'fields'         => 'ID',
			)
		);

		foreach ( $stranded as $id ) {
			wp_delete_user( (int) $id );
		}
	}

	/**
	 * Act as an administrator, who holds every capability the plugin maps.
	 *
	 * @return void
	 */
	protected function actAsAdministrator(): void {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);

		if ( array() === $admins ) {
			$this->markTestSkipped( 'This installation has no administrator to act as.' );
		}

		wp_set_current_user( $admins[0]->ID );
	}

	/**
	 * Act as a freshly created user with a given role.
	 *
	 * Created rather than borrowed: the point is usually to hold *no*
	 * Hiveclerk capability, and an existing account on a development site
	 * may have been given some.
	 *
	 * @param string $role WordPress role.
	 * @return WP_User
	 */
	protected function actAsNewUser( string $role ): WP_User {
		$id = wp_insert_user(
			array(
				'user_login' => self::FIXTURE_PREFIX . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password( 20 ),
				'user_email' => self::FIXTURE_PREFIX . wp_generate_password( 8, false ) . '@example.test',
				'role'       => $role,
			)
		);

		if ( is_wp_error( $id ) ) {
			$this->markTestSkipped( 'Could not create a test user: ' . $id->get_error_message() );
		}

		$this->users[] = (int) $id;

		wp_set_current_user( (int) $id );

		return new WP_User( (int) $id );
	}

	/**
	 * Act as nobody.
	 *
	 * @return void
	 */
	protected function actAsSignedOut(): void {
		wp_set_current_user( 0 );
	}

	/**
	 * Issue a request through the real dispatcher.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route, without the namespace.
	 * @param array<string, mixed> $params Parameters.
	 * @return WP_REST_Response
	 */
	protected function request( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/hiveclerk/v1' . $route );

		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		if ( array() !== $params && 'GET' !== $method ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * A successful response, with its envelope checked.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param string           $where    What was called, for the message.
	 * @return array<string, mixed> The decoded body.
	 */
	protected function assertOkEnvelope( WP_REST_Response $response, string $where ): array {
		$this->assertLessThan(
			300,
			$response->get_status(),
			$where . ' answered ' . $response->get_status() . ': ' . $this->messageOf( $response )
		);

		$body = (array) $response->get_data();

		// 204 carries nothing, by design.
		if ( 204 === $response->get_status() ) {
			return $body;
		}

		$this->assertArrayHasKey(
			'data',
			$body,
			$where . ' answered without the "data" envelope every client reads'
		);

		return $body;
	}

	/**
	 * A refusal, with its status and stable code checked.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param int              $status   Expected status.
	 * @param string           $where    What was called, for the message.
	 * @return string The error code.
	 */
	protected function assertErrorStatus( WP_REST_Response $response, int $status, string $where ): string {
		$this->assertSame(
			$status,
			$response->get_status(),
			$where . ' answered ' . $response->get_status() . ' rather than ' . $status
				. ': ' . $this->messageOf( $response )
		);

		$body = (array) $response->get_data();

		$this->assertArrayHasKey( 'code', $body, $where . ' refused without a machine-readable code' );
		$this->assertIsString( $body['code'] );
		$this->assertNotSame( '', $body['code'], $where . ' refused with an empty code' );

		return (string) $body['code'];
	}

	/**
	 * The message a response carries, for a failure line.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return string
	 */
	protected function messageOf( WP_REST_Response $response ): string {
		$body = (array) $response->get_data();

		if ( isset( $body['message'] ) && is_string( $body['message'] ) ) {
			return $body['message'];
		}

		return (string) wp_json_encode( $body );
	}

	/**
	 * Every registered Hiveclerk route, as the server sees them.
	 *
	 * @return array<int, string>
	 */
	protected function registeredRoutes(): array {
		$routes = array_keys( rest_get_server()->get_routes() );

		return array_values(
			array_filter(
				$routes,
				static fn( string $route ): bool => str_starts_with( $route, '/hiveclerk/v1/' )
			)
		);
	}
}
