<?php
/**
 * HTTP transport contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai\Http;

/**
 * Sends HTTP requests on behalf of the provider adapters.
 *
 * Exists so the adapters can be unit-tested against recorded provider
 * responses without a network, and so the SaaS build can swap in a client
 * that routes through the metered gateway without touching adapter code.
 */
interface HttpClientInterface {

	/**
	 * Send a request and wait for the whole response.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param array<string, mixed>|null $body JSON body, or null.
	 * @param int                   $timeout Seconds.
	 * @return HttpResponse
	 */
	public function request(
		string $method,
		string $url,
		array $headers = array(),
		?array $body = null,
		int $timeout = 60
	): HttpResponse;

	/**
	 * Send a request and receive the body incrementally.
	 *
	 * The callback is invoked with each chunk as it arrives. Returning
	 * false from it aborts the transfer, which is how a closed browser
	 * connection stops the provider bill from continuing to run.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param array<string, mixed>|null $body JSON body, or null.
	 * @param int                   $timeout Seconds.
	 * @param callable(string): bool $onChunk Receives each chunk.
	 * @return int HTTP status code.
	 */
	public function stream(
		string $method,
		string $url,
		array $headers,
		?array $body,
		int $timeout,
		callable $onChunk
	): int;

	/**
	 * Whether this client can deliver a body incrementally.
	 *
	 * False means stream() still works but delivers the whole body in one
	 * call at the end. The chat transport uses this to decide up front
	 * whether to advertise streaming to the widget rather than promising
	 * it and then delivering a single frame after twenty seconds.
	 *
	 * @return bool
	 */
	public function supportsIncrementalStreaming(): bool;
}
