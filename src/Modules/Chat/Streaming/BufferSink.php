<?php
/**
 * Polling delivery.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Streaming;

use Hiveclerk\Modules\Chat\Support\ChatSink;

/**
 * Writes a reply into a buffer another request will read.
 *
 * Always reports the recipient as present. That looks wrong beside
 * {@see SseSink}, where the return value is a real connection check, and
 * it is the honest answer here: there is no connection to check. The
 * poller is a different request that may not have arrived yet. Guessing
 * "gone" because nobody has polled in the last second would abandon
 * replies that were about to be read.
 */
final class BufferSink implements ChatSink {

	/**
	 * Construct.
	 *
	 * @param StreamBuffer $buffer Backing store.
	 * @param string       $key    Buffer key for this generation.
	 */
	public function __construct(
		private readonly StreamBuffer $buffer,
		private readonly string $key
	) {
	}

	public function start( string $messageId, string $conversationId ): bool {
		unset( $conversationId );

		$this->buffer->open( $this->key );

		return true;
	}

	public function delta( string $text ): bool {
		$this->buffer->append( $this->key, $text );

		return true;
	}

	public function replace( string $text ): bool {
		$this->buffer->replace( $this->key, $text );

		return true;
	}

	public function citations( array $citations ): bool {
		$this->buffer->citations( $this->key, $citations );

		return true;
	}

	public function done( array $payload ): bool {
		$this->buffer->complete( $this->key, $payload );

		return true;
	}

	public function error( string $code, string $message, bool $recoverable = true ): bool {
		unset( $recoverable );

		$this->buffer->fail( $this->key, $code, $message );

		return true;
	}
}
