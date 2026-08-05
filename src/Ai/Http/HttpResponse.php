<?php
/**
 * HTTP response.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai\Http;

/**
 * A completed HTTP response, transport-agnostic.
 */
final class HttpResponse {

	/**
	 * Construct.
	 *
	 * @param int                   $status  HTTP status code.
	 * @param string                $body    Raw body.
	 * @param array<string, string> $headers Response headers, lower-cased keys.
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body,
		public readonly array $headers = array()
	) {
	}

	/**
	 * Whether the status is in the 2xx range.
	 *
	 * @return bool
	 */
	public function isOk(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Decode the body as a JSON object.
	 *
	 * @return array<string, mixed> Empty when the body is not a JSON object.
	 */
	public function json(): array {
		$decoded = json_decode( $this->body, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
