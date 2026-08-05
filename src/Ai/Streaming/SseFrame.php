<?php
/**
 * One server-sent event frame.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai\Streaming;

/**
 * A parsed frame: its event name and its data payload.
 */
final class SseFrame {

	/**
	 * Construct.
	 *
	 * @param string $event Event name, empty when unnamed.
	 * @param string $data  Data payload, newline-joined.
	 */
	public function __construct(
		public readonly string $event,
		public readonly string $data
	) {
	}

	/**
	 * Whether this is the OpenAI-style terminator.
	 *
	 * `data: [DONE]` is not JSON and must be recognised before decoding,
	 * or every stream ends with a spurious parse failure.
	 *
	 * @return bool
	 */
	public function isDoneSentinel(): bool {
		return '[DONE]' === trim( $this->data );
	}

	/**
	 * Decode the payload as JSON.
	 *
	 * @return array<string, mixed> Empty when the payload is not an object.
	 */
	public function json(): array {
		$decoded = json_decode( $this->data, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
