<?php
/**
 * Who the REST layer actually turns away.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Integration;

/**
 * The gating assertion, made by the thing that does the gating.
 *
 * The unit suite invokes each permission callback directly and checks it
 * returns 401 and 403. That proves the callback refuses. It does not
 * prove WordPress *asks* it — a route whose callback is perfect but whose
 * registration lost its `permission_callback` key would pass there and be
 * open here.
 *
 * So this drives real requests through `rest_do_request()` as a signed-out
 * caller and as a subscriber, and expects to be refused by every admin
 * route the server has registered. Subscriber rather than a role with
 * some access: the plugin maps seven capabilities across editor and
 * shop_manager, and the question this asks is what somebody with none of
 * them can reach.
 *
 * @internal
 */
final class RestAuthorizationTest extends RestTestCase {

	/**
	 * Routes an unauthenticated caller is *meant* to reach.
	 *
	 * The widget is served to visitors, so its routes authenticate with a
	 * signed session token rather than a capability and must not be
	 * expected to answer 401. They are gated — `verify-routes.php` and the
	 * unit contract both check that — just not by who is signed in.
	 *
	 * @var array<int, string>
	 */
	private const PUBLIC_PREFIXES = array(
		'/hiveclerk/v1/public',
		'/hiveclerk/v1/widget',
		'/hiveclerk/v1/unsubscribe',
	);

	/**
	 * Every gated route the server has registered, with a safe method.
	 *
	 * Everything the plugin serves that is not the widget. The first
	 * version of this filtered on `/admin` and silently omitted the four
	 * `/system` routes — which are gated on `manage_settings` and are
	 * exactly the sort of thing worth checking, since they report the
	 * install's configuration.
	 *
	 * Only GET is exercised. A POST or DELETE that somehow got through the
	 * gate would act on the developer's data, and a test that has to
	 * succeed at being refused must not be destructive when it is not.
	 *
	 * @return array<int, string>
	 */
	private function adminGetRoutes(): array {
		$server = rest_get_server();
		$routes = array();

		foreach ( $server->get_routes() as $route => $handlers ) {
			if ( ! str_starts_with( $route, '/hiveclerk/v1/' ) || $this->isPublic( $route ) ) {
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
	 * A path a route pattern will actually match.
	 *
	 * Patterns carry regex groups for ids and uuids. Substituting a
	 * plausible-looking value gets the request as far as the permission
	 * callback, which is the only thing under test here — what the handler
	 * would then do with a nonexistent id is a different test.
	 *
	 * @param string $pattern Registered route pattern.
	 * @return string
	 */
	private function concreteFor( string $pattern ): string {
		$path = preg_replace( '/\(\?P<[a-z_]+>\[a-f0-9-\]\{36\}\)/', '00000000-0000-4000-8000-000000000000', $pattern );
		$path = preg_replace( '/\(\?P<[a-z_]+>\\\\d\+\)/', '999999', (string) $path );
		$path = preg_replace( '/\(\?P<[a-z_]+>[^)]+\)/', 'placeholder', (string) $path );

		return (string) $path;
	}

	public function testTheServerHasRoutesToCheck(): void {
		$this->actAsAdministrator();

		// 87 gated route patterns are registered — 83 under /admin and 4
		// under /system — of which 48 serve GET; the rest are write-only.
		// The floor is under the real number so a new route does not fail
		// this, and far enough above zero that a server which failed to
		// boot does.
		$this->assertGreaterThanOrEqual(
			40,
			count( $this->adminGetRoutes() ),
			'far fewer admin routes than the plugin registers — the server did not boot properly'
		);
	}

	/**
	 * Nobody signed in reaches an admin route.
	 */
	public function testASignedOutCallerIsRefusedEverywhere(): void {
		$this->actAsSignedOut();

		$allowed = array();

		foreach ( $this->adminGetRoutes() as $pattern ) {
			if ( $this->isPublic( $pattern ) ) {
				continue;
			}

			$response = $this->request( 'GET', substr( $this->concreteFor( $pattern ), strlen( '/hiveclerk/v1' ) ) );

			if ( $response->get_status() < 400 ) {
				$allowed[] = $pattern . ' -> ' . $response->get_status();
			}
		}

		$this->assertSame( array(), $allowed, 'these admin routes answered a signed-out caller' );
	}

	/**
	 * A user with an account but no Hiveclerk capability reaches nothing.
	 */
	public function testASubscriberIsRefusedEverywhere(): void {
		$this->actAsNewUser( 'subscriber' );

		$allowed = array();

		foreach ( $this->adminGetRoutes() as $pattern ) {
			if ( $this->isPublic( $pattern ) ) {
				continue;
			}

			$response = $this->request( 'GET', substr( $this->concreteFor( $pattern ), strlen( '/hiveclerk/v1' ) ) );

			if ( $response->get_status() < 400 ) {
				$allowed[] = $pattern . ' -> ' . $response->get_status();
			}
		}

		$this->assertSame( array(), $allowed, 'these admin routes answered a user with no capability' );
	}

	/**
	 * The refusal says which of the two problems it is.
	 *
	 * 401 and 403 are different instructions: sign in, versus ask an
	 * administrator for access. Collapsing them sends somebody to the
	 * wrong place.
	 */
	public function testTheRefusalDistinguishesSignedOutFromUnprivileged(): void {
		$route = '/admin/agents';

		$this->actAsSignedOut();
		$anonymous = $this->request( 'GET', $route );
		$this->assertErrorStatus( $anonymous, 401, 'signed out on ' . $route );

		$this->actAsNewUser( 'subscriber' );
		$unprivileged = $this->request( 'GET', $route );
		$this->assertErrorStatus( $unprivileged, 403, 'subscriber on ' . $route );
	}

	/**
	 * And an administrator is not refused, or the tests above prove only
	 * that the plugin is broken for everybody.
	 */
	public function testAnAdministratorIsAdmitted(): void {
		$this->actAsAdministrator();

		$this->assertOkEnvelope( $this->request( 'GET', '/admin/agents' ), 'GET /admin/agents' );
	}

	/**
	 * Whether a route is one the widget serves to visitors.
	 *
	 * @param string $pattern Route pattern.
	 * @return bool
	 */
	private function isPublic( string $pattern ): bool {
		foreach ( self::PUBLIC_PREFIXES as $prefix ) {
			if ( str_starts_with( $pattern, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
