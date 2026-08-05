<?php
/**
 * Server-sent event delivery.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Streaming;

use Hiveclerk\Api\Streaming\SseStream;
use Hiveclerk\Modules\Chat\Support\ChatSink;

/**
 * Writes a reply to an open event stream.
 *
 * Thin on purpose. Everything difficult about SSE — the header set, the
 * buffer teardown, the padding, the abort detection — lives in
 * {@see SseStream} and was measured in the Sprint 3 spike. This class only
 * decides which frame name carries which payload, and that mapping is the
 * public contract in the API specification.
 *
 * @see docs/09-api-specification.md §2.3
 */
final class SseSink implements ChatSink {

	/**
	 * Construct.
	 *
	 * @param SseStream $stream The open connection.
	 */
	public function __construct(
		private readonly SseStream $stream
	) {
	}

	public function start( string $messageId, string $conversationId ): bool {
		return $this->stream->send(
			'start',
			array(
				'message_id'   => $messageId,
				'conversation' => $conversationId,
			)
		);
	}

	public function delta( string $text ): bool {
		// The heartbeat runs before the frame rather than after it. A
		// provider that stalls for a minute between tokens is the case this
		// exists for, and by the time the next token arrives the connection
		// it would have kept alive is already gone.
		$this->stream->heartbeat();

		return $this->stream->send( 'delta', array( 'text' => $text ) );
	}

	public function replace( string $text ): bool {
		return $this->stream->send( 'replace', array( 'text' => $text ) );
	}

	public function citations( array $citations ): bool {
		return $this->stream->send( 'citations', array( 'citations' => $citations ) );
	}

	public function done( array $payload ): bool {
		return $this->stream->send( 'done', $payload );
	}

	public function error( string $code, string $message, bool $recoverable = true ): bool {
		return $this->stream->send(
			'error',
			array(
				'code'        => $code,
				'message'     => $message,
				'recoverable' => $recoverable,
			)
		);
	}
}
