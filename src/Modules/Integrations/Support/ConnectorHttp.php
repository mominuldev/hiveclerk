<?php
/**
 * Outbound HTTP for connectors.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Support;

use Hiveclerk\Infrastructure\Http\OutboundUrlGuard;
use WP_Error;

/**
 * One place every connector's HTTP goes through.
 *
 * Three things are enforced here rather than in five connectors:
 *
 * 1. **TLS is verified.** Not negotiable, and not a per-connector option
 *    somebody can turn off while debugging and forget to turn back on.
 * 2. **Customer-supplied URLs are checked first.** A webhook endpoint is
 *    an SSRF primitive; a hard-coded api.hubapi.com is not. The guard runs
 *    for the first kind, which is why `$trusted` exists — running a DNS
 *    resolution before every HubSpot call would add a round trip to every
 *    sync for no benefit.
 * 3. **Redirects are not followed.** A 302 from an endpoint the guard
 *    approved to one it would not have is the simplest way around the
 *    check, and no API this talks to answers a POST with a redirect.
 */
final class ConnectorHttp {

	/**
	 * Seconds to wait.
	 *
	 * Jobs are allowed roughly twenty seconds in total and a push may
	 * involve a token refresh followed by the call itself, so no single
	 * request may sit for longer than this.
	 */
	private const TIMEOUT = 10;

	/**
	 * Construct.
	 *
	 * @param OutboundUrlGuard $guard Private-network check.
	 */
	public function __construct( private readonly OutboundUrlGuard $guard ) {
	}

	/**
	 * A GET.
	 *
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param bool                  $trusted Whether the URL is ours rather than the customer's.
	 * @return ConnectorResponse
	 */
	public function get( string $url, array $headers = array(), bool $trusted = true ): ConnectorResponse {
		return $this->send( 'GET', $url, $headers, null, $trusted );
	}

	/**
	 * A JSON POST.
	 *
	 * @param string                $url     Absolute URL.
	 * @param array<string, mixed>  $body    Body, encoded as JSON.
	 * @param array<string, string> $headers Request headers.
	 * @param bool                  $trusted Whether the URL is ours rather than the customer's.
	 * @return ConnectorResponse
	 */
	public function postJson(
		string $url,
		array $body,
		array $headers = array(),
		bool $trusted = true
	): ConnectorResponse {
		$headers['Content-Type'] = 'application/json';

		return $this->send( 'POST', $url, $headers, (string) wp_json_encode( $body ), $trusted );
	}

	/**
	 * A JSON POST whose body was encoded by the caller.
	 *
	 * The webhook signature is computed over the exact bytes that get
	 * sent, so the dispatcher encodes once and signs what it encoded.
	 * Re-encoding here would let a difference in escaping produce a
	 * signature the receiver cannot verify.
	 *
	 * @param string                $url     Absolute URL.
	 * @param string                $body    Encoded body.
	 * @param array<string, string> $headers Request headers.
	 * @param bool                  $trusted Whether the URL is ours rather than the customer's.
	 * @return ConnectorResponse
	 */
	public function postRaw(
		string $url,
		string $body,
		array $headers = array(),
		bool $trusted = false
	): ConnectorResponse {
		$headers['Content-Type'] = 'application/json';

		return $this->send( 'POST', $url, $headers, $body, $trusted );
	}

	/**
	 * A form-encoded POST, which OAuth token endpoints require.
	 *
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $fields  Form fields.
	 * @param array<string, string> $headers Request headers.
	 * @return ConnectorResponse
	 */
	public function postForm( string $url, array $fields, array $headers = array() ): ConnectorResponse {
		$headers['Content-Type'] = 'application/x-www-form-urlencoded';

		return $this->send( 'POST', $url, $headers, http_build_query( $fields ), true );
	}

	/**
	 * A JSON PATCH.
	 *
	 * @param string                $url     Absolute URL.
	 * @param array<string, mixed>  $body    Body, encoded as JSON.
	 * @param array<string, string> $headers Request headers.
	 * @return ConnectorResponse
	 */
	public function patchJson( string $url, array $body, array $headers = array() ): ConnectorResponse {
		$headers['Content-Type'] = 'application/json';

		return $this->send( 'PATCH', $url, $headers, (string) wp_json_encode( $body ), true );
	}

	/**
	 * Send one request.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param string|null           $body    Encoded body.
	 * @param bool                  $trusted Whether the URL is ours rather than the customer's.
	 * @return ConnectorResponse
	 */
	private function send(
		string $method,
		string $url,
		array $headers,
		?string $body,
		bool $trusted
	): ConnectorResponse {
		if ( ! str_starts_with( $url, 'https://' ) ) {
			return new ConnectorResponse( 0, array(), 'Only https endpoints are allowed.' );
		}

		if ( ! $trusted && $this->guard->isBlocked( $url ) ) {
			return new ConnectorResponse(
				0,
				array(),
				'That address resolves to a private network and was not contacted.'
			);
		}

		$args = array(
			'method'      => $method,
			'headers'     => array_merge( array( 'Accept' => 'application/json' ), $headers ),
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'sslverify'   => true,
			'user-agent'  => 'Hiveclerk/' . ( defined( 'HIVECLERK_VERSION' ) ? (string) HIVECLERK_VERSION : '1.0' ),
		);

		if ( null !== $body ) {
			$args['body']        = $body;
			$args['data_format'] = 'body';
		}

		$response = wp_remote_request( $url, $args );

		if ( $response instanceof WP_Error ) {
			return new ConnectorResponse( 0, array(), $response->get_error_message() );
		}

		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		return new ConnectorResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			is_array( $decoded ) ? $decoded : array(),
			$raw
		);
	}
}
