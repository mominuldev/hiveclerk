<?php
/**
 * Every readable route, driven for real.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Integration;

/**
 * What happens when a request actually arrives.
 *
 * The unit contract asserts what controllers declare and never invokes a
 * handler; this invokes every readable one. It is a smoke test in the
 * useful sense — it will not tell you a figure is wrong, but it will tell
 * you a handler fatals, returns something no client can parse, or answers
 * a missing record with a 500 instead of a 404.
 *
 * Only GET is exercised, and only as an administrator. A write test on a
 * developer's own install would need to create and then destroy real
 * records through endpoints whose failure mode is destroying the wrong
 * ones, and the read surface is where an unreviewed handler is most
 * likely to be waiting.
 *
 * @internal
 */
final class RestReadSurfaceTest extends RestTestCase {

	/**
	 * Routes whose body is not a JSON envelope, by design.
	 *
	 * Both stream. They hook `rest_pre_serve_request` and write frames
	 * directly, returning an empty `WP_REST_Response` because the
	 * serialiser is not what produces their output — so asserting an
	 * envelope on them would be asserting the wrong contract. What they do
	 * over time is measured by `tools/sse-probe.mjs`, which is the right
	 * shape of tool for it.
	 *
	 * Asserted to exist by `testTheSkipListNamesRealRoutes()`. The first
	 * version of this list named `/admin/chat/stream`, which is not a
	 * route this plugin has ever registered — an exclusion for something
	 * imaginary, silently excluding nothing.
	 *
	 * @var array<int, string>
	 */
	private const SKIP = array(
		'/hiveclerk/v1/system/stream/probe',
		'/hiveclerk/v1/system/stream/environment',
	);

	/**
	 * An exclusion that names nothing excludes nothing.
	 */
	public function testTheSkipListNamesRealRoutes(): void {
		$registered = array_keys( rest_get_server()->get_routes() );

		foreach ( self::SKIP as $route ) {
			$this->assertContains(
				$route,
				$registered,
				$route . ' is skipped but is not a route this plugin registers'
			);
		}
	}

	/**
	 * Admin GET routes that take no path parameter.
	 *
	 * @return array<int, string>
	 */
	private function parameterlessRoutes(): array {
		$routes = array();

		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( ! str_starts_with( $route, '/hiveclerk/v1/' ) || str_starts_with( $route, '/hiveclerk/v1/public' ) ) {
				continue;
			}

			if ( str_contains( $route, '(?P<' ) || in_array( $route, self::SKIP, true ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				if ( ! empty( $handler['methods']['GET'] ) ) {
					$routes[] = $route;

					break;
				}
			}
		}

		return $routes;
	}

	/**
	 * Admin GET routes keyed by a uuid.
	 *
	 * @return array<int, string>
	 */
	private function uuidRoutes(): array {
		$routes = array();

		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( ! str_starts_with( $route, '/hiveclerk/v1/' ) || str_starts_with( $route, '/hiveclerk/v1/public' ) ) {
				continue;
			}

			if ( ! str_contains( $route, '[a-f0-9-]{36}' ) || in_array( $route, self::SKIP, true ) ) {
				continue;
			}

			// Only routes whose *only* parameter is the uuid, so a 404 is
			// unambiguous rather than possibly about a second missing id.
			if ( 1 !== substr_count( $route, '(?P<' ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				if ( ! empty( $handler['methods']['GET'] ) ) {
					$routes[] = $route;

					break;
				}
			}
		}

		return $routes;
	}

	/**
	 * No readable route fatals or answers something unparseable.
	 */
	public function testEveryReadableRouteAnswersAnEnvelope(): void {
		$this->actAsAdministrator();

		$routes = $this->parameterlessRoutes();
		$broken = array();

		$this->assertGreaterThan( 10, count( $routes ), 'no readable routes were found to exercise' );

		foreach ( $routes as $route ) {
			$path     = substr( $route, strlen( '/hiveclerk/v1' ) );
			$response = $this->request( 'GET', $path );

			if ( $response->get_status() >= 500 ) {
				$broken[] = $path . ' -> ' . $response->get_status() . ' ' . $this->messageOf( $response );

				continue;
			}

			// A 4xx here is a handler saying something is not configured,
			// which is a legitimate answer on a development install — a
			// provider with no key, a licence that is not active. What is
			// not legitimate is a body no client can read.
			$body = (array) $response->get_data();

			if ( $response->get_status() < 300 && ! array_key_exists( 'data', $body ) ) {
				$broken[] = $path . ' -> 2xx with no "data" envelope';
			}

			if ( $response->get_status() >= 400 && ! array_key_exists( 'code', $body ) ) {
				$broken[] = $path . ' -> ' . $response->get_status() . ' with no error code';
			}
		}

		$this->assertSame( array(), $broken );
	}

	/**
	 * A record that does not exist is a 404, never a 500.
	 *
	 * A uuid that is well-formed and unknown is the ordinary case — a
	 * bookmarked link to a deleted conversation, a stale tab. Answering it
	 * with a fatal turns a missing record into an error page.
	 */
	public function testAnUnknownUuidIsNotFound(): void {
		$this->actAsAdministrator();

		$missing = '00000000-0000-4000-8000-000000000000';
		$routes  = $this->uuidRoutes();
		$wrong   = array();

		$this->assertGreaterThan( 2, count( $routes ), 'no uuid-keyed routes were found to exercise' );

		foreach ( $routes as $route ) {
			$path = str_replace(
				array( '(?P<uuid>[a-f0-9-]{36})', '(?P<id>[a-f0-9-]{36})' ),
				$missing,
				substr( $route, strlen( '/hiveclerk/v1' ) )
			);

			$response = $this->request( 'GET', $path );

			if ( 404 !== $response->get_status() ) {
				$wrong[] = $path . ' -> ' . $response->get_status() . ' ' . $this->messageOf( $response );
			}
		}

		$this->assertSame( array(), $wrong, 'these routes did not answer a well-formed unknown uuid with 404' );
	}

	/**
	 * A collection carries the pagination a client needs to page it.
	 */
	public function testCollectionsCarryPaginationMeta(): void {
		$this->actAsAdministrator();

		foreach ( array( '/admin/agents', '/admin/conversations', '/admin/leads' ) as $route ) {
			$body = $this->assertOkEnvelope( $this->request( 'GET', $route ), 'GET ' . $route );

			$this->assertArrayHasKey( 'meta', $body, $route . ' returned a collection with no meta' );
			$this->assertArrayHasKey( 'pagination', $body['meta'], $route . ' returned no pagination' );

			foreach ( array( 'page', 'per_page', 'total', 'total_pages' ) as $key ) {
				$this->assertArrayHasKey(
					$key,
					$body['meta']['pagination'],
					$route . ' pagination is missing ' . $key
				);
			}
		}
	}

	/**
	 * The page size is clamped by the dispatcher, not trusted.
	 *
	 * `per_page` carries a maximum in its route args, which only means
	 * anything because `rest_do_request()` enforces it — the unit suite
	 * cannot show this, because its request object does not run WordPress's
	 * argument validation at all.
	 */
	public function testAnAbsurdPageSizeIsRefusedOrClamped(): void {
		$this->actAsAdministrator();

		$response = $this->request( 'GET', '/admin/agents', array( 'per_page' => 100000 ) );

		if ( $response->get_status() >= 400 ) {
			$this->assertErrorStatus( $response, 400, 'GET /admin/agents with per_page=100000' );

			return;
		}

		$body = (array) $response->get_data();

		$this->assertLessThanOrEqual(
			100,
			(int) $body['meta']['pagination']['per_page'],
			'an unbounded per_page reached the query'
		);
	}

	/**
	 * The system health endpoint is what an operator opens when something
	 * is wrong, so it has to answer when things are wrong.
	 */
	public function testSystemHealthReportsTheThingsTheStatusScreenReads(): void {
		$this->actAsAdministrator();

		$body = $this->assertOkEnvelope( $this->request( 'GET', '/system/health' ), 'GET /system/health' );
		$data = (array) $body['data'];

		foreach ( array( 'php', 'wordpress', 'database', 'queue', 'cron' ) as $section ) {
			$this->assertArrayHasKey( $section, $data, 'system health reported no ' . $section );
		}
	}
}
