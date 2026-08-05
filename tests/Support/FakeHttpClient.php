<?php
/**
 * Scripted HTTP transport for tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support;

use Hiveclerk\Ai\Http\HttpClientInterface;
use Hiveclerk\Ai\Http\HttpResponse;

/**
 * Replays recorded provider responses.
 *
 * Streams are supplied as a list of chunks so a test can put the split
 * anywhere it likes — including mid-frame, which is the case that matters
 * and the one a live provider will not reproduce on demand.
 */
final class FakeHttpClient implements HttpClientInterface {

	/**
	 * Requests this client was asked to make.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = array();

	/**
	 * Construct.
	 *
	 * @param HttpResponse      $response Response for request().
	 * @param array<int, string> $chunks  Chunks for stream().
	 * @param int               $status   Status for stream().
	 */
	public function __construct(
		private readonly HttpResponse $response = new HttpResponse( 200, '{}' ),
		private readonly array $chunks = array(),
		private readonly int $status = 200
	) {
	}

	/**
	 * Return the scripted response.
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $url     URL.
	 * @param array<string, string>     $headers Headers.
	 * @param array<string, mixed>|null $body    Body.
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
		$this->calls[] = compact( 'method', 'url', 'headers', 'body', 'timeout' );

		return $this->response;
	}

	/**
	 * Replay the scripted chunks.
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $url     URL.
	 * @param array<string, string>     $headers Headers.
	 * @param array<string, mixed>|null $body    Body.
	 * @param int                       $timeout Seconds.
	 * @param callable(string): bool    $onChunk Chunk sink.
	 * @return int
	 */
	public function stream(
		string $method,
		string $url,
		array $headers,
		?array $body,
		int $timeout,
		callable $onChunk
	): int {
		$this->calls[] = compact( 'method', 'url', 'headers', 'body', 'timeout' );

		foreach ( $this->chunks as $chunk ) {
			if ( false === $onChunk( $chunk ) ) {
				break;
			}
		}

		return $this->status;
	}

	/**
	 * Always incremental, so tests exercise the real path.
	 *
	 * @return bool
	 */
	public function supportsIncrementalStreaming(): bool {
		return true;
	}

	/**
	 * The body of the last request made.
	 *
	 * @return array<string, mixed>
	 */
	public function lastBody(): array {
		$last = end( $this->calls );

		return is_array( $last ) && is_array( $last['body'] ?? null ) ? $last['body'] : array();
	}

	/**
	 * The URL of the last request made.
	 *
	 * @return string
	 */
	public function lastUrl(): string {
		$last = end( $this->calls );

		return is_array( $last ) && is_string( $last['url'] ?? null ) ? $last['url'] : '';
	}
}
