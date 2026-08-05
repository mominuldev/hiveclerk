<?php
/**
 * WordPress HTTP transport.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Infrastructure\Http;

use Hiveclerk\Ai\Http\HttpClientInterface;
use Hiveclerk\Ai\Http\HttpResponse;
use WP_Error;

/**
 * Sends provider requests through WordPress, with a cURL path for streams.
 *
 * Buffered requests use the WordPress HTTP API, which is the right choice:
 * it honours a site's proxy constants, its SSL configuration and the
 * http_request_args filters that hosts rely on.
 *
 * Streaming cannot use it. wp_remote_request() returns only once the whole
 * body has arrived — there is no incremental callback anywhere in the API
 * — so a streamed completion would sit silent for the full generation and
 * then arrive at once, which is precisely the experience streaming exists
 * to avoid. cURL's write callback is the only way to get bytes as they
 * land, so streaming drops to cURL directly and the buffered fallback is
 * kept honest by supportsIncrementalStreaming() reporting which one is in
 * play.
 */
final class WpHttpClient implements HttpClientInterface {

	/**
	 * Buffered request through the WordPress HTTP API.
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $url     Absolute URL.
	 * @param array<string, string>     $headers Request headers.
	 * @param array<string, mixed>|null $body    JSON body.
	 * @param int                       $timeout Seconds.
	 * @return HttpResponse
	 */
	public function request(
		string $method,
		string $url,
		array $headers = array(),
		?array $body = null,
		int $timeout = 60
	): HttpResponse {
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $timeout,
			'redirection' => 0,
			'user-agent'  => self::userAgent(),
		);

		$encoded = self::encode( $body );

		if ( null !== $encoded ) {
			$args['body']                    = $encoded;
			$args['headers']['Content-Type'] = 'application/json';
		}

		$response = wp_remote_request( $url, $args );

		if ( $response instanceof WP_Error ) {
			// Status 0 marks a transport failure the provider never saw,
			// which the adapters treat as retryable.
			return new HttpResponse( 0, $response->get_error_message() );
		}

		$responseHeaders = wp_remote_retrieve_headers( $response );

		return new HttpResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			wp_remote_retrieve_body( $response ),
			is_object( $responseHeaders ) ? $responseHeaders->getAll() : array()
		);
	}

	/**
	 * Streamed request through cURL.
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $url     Absolute URL.
	 * @param array<string, string>     $headers Request headers.
	 * @param array<string, mixed>|null $body    JSON body.
	 * @param int                       $timeout Seconds.
	 * @param callable(string): bool    $onChunk Receives each chunk.
	 * @return int HTTP status code, or 0 on transport failure.
	 */
	public function stream(
		string $method,
		string $url,
		array $headers,
		?array $body,
		int $timeout,
		callable $onChunk
	): int {
		if ( ! $this->supportsIncrementalStreaming() ) {
			return $this->bufferedStream( $method, $url, $headers, $body, $timeout, $onChunk );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_close
		$handle = curl_init( $url );

		if ( false === $handle ) {
			return 0;
		}

		$lines = array();

		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		$encoded = self::encode( $body );

		if ( null !== $encoded ) {
			$lines[] = 'Content-Type: application/json';

			curl_setopt( $handle, CURLOPT_POSTFIELDS, $encoded );
		}

		curl_setopt( $handle, CURLOPT_CUSTOMREQUEST, self::method( $method ) );
		curl_setopt( $handle, CURLOPT_HTTPHEADER, $lines );
		curl_setopt( $handle, CURLOPT_RETURNTRANSFER, false );
		curl_setopt( $handle, CURLOPT_TIMEOUT, $timeout );
		curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
		curl_setopt( $handle, CURLOPT_USERAGENT, self::userAgent() );

		/*
		 * Returning a byte count shorter than the chunk aborts the
		 * transfer, which is how a disconnected visitor stops us paying
		 * for tokens nobody will read.
		 */
		curl_setopt(
			$handle,
			CURLOPT_WRITEFUNCTION,
			static function ( $unusedHandle, string $chunk ) use ( $onChunk ): int {
				unset( $unusedHandle );

				return false === $onChunk( $chunk ) ? 0 : strlen( $chunk );
			}
		);

		curl_exec( $handle );

		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );

		curl_close( $handle );
		// phpcs:enable

		return $status;
	}

	/**
	 * Whether cURL's write callback is available.
	 *
	 * @return bool
	 */
	public function supportsIncrementalStreaming(): bool {
		return function_exists( 'curl_init' )
			&& function_exists( 'curl_setopt' )
			&& defined( 'CURLOPT_WRITEFUNCTION' );
	}

	/**
	 * Fallback that delivers the whole body in one call.
	 *
	 * Correct but not incremental. Kept so a host without cURL still gets
	 * an answer rather than an error, and reported honestly through
	 * supportsIncrementalStreaming() so the caller does not promise the
	 * visitor a live stream it cannot deliver.
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $url     Absolute URL.
	 * @param array<string, string>     $headers Request headers.
	 * @param array<string, mixed>|null $body    JSON body.
	 * @param int                       $timeout Seconds.
	 * @param callable(string): bool    $onChunk Receives the body.
	 * @return int
	 */
	private function bufferedStream(
		string $method,
		string $url,
		array $headers,
		?array $body,
		int $timeout,
		callable $onChunk
	): int {
		$response = $this->request( $method, $url, $headers, $body, $timeout );

		if ( '' !== $response->body ) {
			$onChunk( $response->body );
		}

		return $response->status;
	}

	/**
	 * Encode a request body, or null when there is none to send.
	 *
	 * An unencodable body is treated as no body rather than as an empty
	 * one: sending a zero-length payload where JSON was expected produces
	 * a confusing 400 from the provider, while sending nothing at all
	 * fails in a way that points back here.
	 *
	 * @param array<string, mixed>|null $body Body.
	 * @return non-empty-string|null
	 */
	private static function encode( ?array $body ): ?string {
		if ( null === $body ) {
			return null;
		}

		$encoded = wp_json_encode( $body );

		return is_string( $encoded ) && '' !== $encoded ? $encoded : null;
	}

	/**
	 * Normalise the HTTP method to one we actually issue.
	 *
	 * An allowlist rather than a pass-through. The method goes into the
	 * request line verbatim, and a caller-supplied string reaching that
	 * position is the shape of a request-smuggling bug — even though
	 * every caller today passes a literal.
	 *
	 * @param string $method Requested method.
	 * @return non-empty-string
	 */
	private static function method( string $method ): string {
		return match ( strtoupper( $method ) ) {
			'POST'   => 'POST',
			'PUT'    => 'PUT',
			'PATCH'  => 'PATCH',
			'DELETE' => 'DELETE',
			default  => 'GET',
		};
	}

	/**
	 * Identify ourselves to the provider.
	 *
	 * @return non-empty-string
	 */
	private static function userAgent(): string {
		return 'Hiveclerk/' . ( defined( 'HIVECLERK_VERSION' ) ? HIVECLERK_VERSION : 'dev' );
	}
}
