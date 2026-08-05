<?php
/**
 * Server-sent event transport.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Api\Streaming;

/**
 * Holds an HTTP response open and pushes frames down it.
 *
 * Streaming is the one part of this plugin that depends on the host
 * rather than on PHP. Everything between us and the browser is entitled
 * to buffer: PHP's own output buffer, zlib, Apache's mod_deflate, nginx's
 * proxy_buffer, a CDN, and on shared hosting usually two of those at
 * once. A buffered event stream is not broken in any way a test would
 * notice — every byte arrives, in order, correct. It just all arrives at
 * the end, which turns a streaming answer into a slow one.
 *
 * So this class is mostly a list of things to switch off, each of which
 * was chosen because something switches it back on somewhere.
 *
 * @see \Hiveclerk\Api\Streaming\StreamEnvironment for what it cannot fix.
 */
final class SseStream {

	/**
	 * Bytes of comment padding sent before the first real frame.
	 *
	 * Four kilobytes, per the architecture decision, and the figure holds
	 * up against a measurement: this host runs output_buffering=4096, so
	 * anything smaller would sit inside the default buffer on any host
	 * where tearDownBuffers() fails. Paying it once at connect is cheap;
	 * paying it as first-token latency is the only delay in a streamed
	 * answer that a reader actually notices.
	 *
	 * @see docs/06-system-architecture.md §5.1
	 */
	public const PADDING_BYTES = 4096;

	/**
	 * Seconds of silence before a keep-alive comment is due.
	 *
	 * Under most idle-connection timeouts, and short enough that a
	 * disconnected client is noticed while there is still work to cancel.
	 */
	public const HEARTBEAT_SECONDS = 15;

	/**
	 * How long a reconnecting EventSource is told to wait.
	 *
	 * Deliberately long. An automatic reconnect restarts the completion,
	 * and the customer pays for both. Resuming is a product decision, not
	 * something the browser should make on its own.
	 */
	private const RETRY_MS = 600000;

	/**
	 * Whether open() has run.
	 *
	 * @var bool
	 */
	private bool $open = false;

	/**
	 * Whether the client has gone.
	 *
	 * @var bool
	 */
	private bool $closed = false;

	/**
	 * Monotonic time of the last byte written.
	 *
	 * @var float
	 */
	private float $lastWrite = 0.0;

	/**
	 * Bytes discarded from pre-existing output buffers.
	 *
	 * @var int
	 */
	private int $discarded = 0;

	/**
	 * Begin the stream.
	 *
	 * Everything here has to happen before the first frame, and most of
	 * it has to happen before any output at all, so it is one method
	 * rather than several the caller could order wrongly.
	 *
	 * @return void
	 */
	public function open(): void {
		if ( $this->open ) {
			return;
		}

		$this->open      = true;
		$this->lastWrite = $this->clock();

		$this->disableCompression();
		$this->relaxLimits();
		$this->sendHeaders();
		$this->discarded = $this->tearDownBuffers();

		// Padding first, then the probe marker. The widget starts a 2.5s
		// timer on connect and falls back to polling if the probe has not
		// arrived — so the marker must come after the padding, or it would
		// arrive early on precisely the hosts the padding exists for.
		$this->push( SseEncoder::comment( str_repeat( '.', self::PADDING_BYTES ) ) );
		$this->push( SseEncoder::retry( self::RETRY_MS ) );
		$this->push( SseEncoder::comment( 'probe' ) );
		$this->flush();
	}

	/**
	 * Send a named event.
	 *
	 * @param string              $event Event name.
	 * @param array<string,mixed> $data  Payload.
	 * @return bool Whether the client is still connected.
	 */
	public function send( string $event, array $data = array() ): bool {
		$this->open();
		$this->push( SseEncoder::event( $event, $data ) );
		$this->flush();

		return ! $this->aborted();
	}

	/**
	 * Send a keep-alive, but only if the stream has gone quiet.
	 *
	 * Called from inside generation loops, where the gap between tokens is
	 * usually milliseconds and occasionally a provider stall of a minute.
	 * Only the second case needs a byte on the wire.
	 *
	 * @return bool Whether the client is still connected.
	 */
	public function heartbeat(): bool {
		if ( ! $this->open ) {
			return ! $this->aborted();
		}

		if ( $this->clock() - $this->lastWrite < self::HEARTBEAT_SECONDS ) {
			return ! $this->aborted();
		}

		$this->push( SseEncoder::comment( 'keep-alive' ) );
		$this->flush();

		return ! $this->aborted();
	}

	/**
	 * Whether the client has disconnected.
	 *
	 * PHP only learns this by trying to write, which is why the check is
	 * made after each frame rather than polled. A caller that stops
	 * generating here stops spending the customer's money on an answer
	 * nobody is reading.
	 *
	 * @return bool
	 */
	public function aborted(): bool {
		if ( $this->closed ) {
			return true;
		}

		$this->closed = 1 === connection_aborted();

		return $this->closed;
	}

	/**
	 * Bytes discarded from output buffers when the stream opened.
	 *
	 * Non-zero means something echoed into our response before we did —
	 * a notice, or a plugin printing during init. That output would have
	 * been prefixed to the first frame and broken the whole stream, so it
	 * is dropped, but it is worth surfacing rather than hiding.
	 *
	 * @return int
	 */
	public function discardedBytes(): int {
		return $this->discarded;
	}

	/**
	 * Stop compression, which is buffering under another name.
	 *
	 * A compressor cannot emit a byte until it has enough input to
	 * compress, so a compressed event stream is a buffered one by
	 * construction, no matter what the flush calls say.
	 *
	 * @return void
	 */
	private function disableCompression(): void {
		if ( function_exists( 'apache_setenv' ) ) {
			// Apache reads these when deciding whether mod_deflate applies.
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@apache_setenv( 'dont-vary', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// Silenced deliberately: these are ini settings a host is entitled
		// to lock, and a warning printed into the response body would be
		// the very corruption this method exists to avoid.
		@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		@ini_set( 'output_buffering', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		@ini_set( 'implicit_flush', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky

		ob_implicit_flush( true );
	}

	/**
	 * Give the request room to run and keep control of when it ends.
	 *
	 * ignore_user_abort() is set the way it is on purpose. The instinct is
	 * to let PHP kill the script the moment the visitor leaves, but the
	 * tokens already generated have been billed whether or not anyone read
	 * them, and the usage row recording that is written after the loop.
	 * Being killed mid-write loses the record and understates spend. We
	 * take responsibility for stopping instead, via aborted().
	 *
	 * @return void
	 */
	private function relaxLimits(): void {
		ignore_user_abort( true );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Send the response headers.
	 *
	 * @return void
	 */
	private function sendHeaders(): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'Content-Type: text/event-stream; charset=utf-8' );
		// no-transform is not decoration. It is the instruction that tells
		// an intermediate cache or CDN it may not recompress or otherwise
		// rewrite the body — and rewriting a body means holding it first.
		header( 'Cache-Control: no-cache, no-store, no-transform, must-revalidate, private' );
		header( 'Pragma: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Content-Type-Options: nosniff' );

		// nginx forwards this instead of buffering the proxied response.
		// It is the single most effective line in this class on the hosts
		// that put nginx in front of PHP-FPM, which is most of them.
		header( 'X-Accel-Buffering: no' );

		// mod_deflate skips a response that already declares an encoding.
		// "none" is not a real encoding, which is exactly why it works:
		// clients ignore it and Apache leaves the body alone.
		header( 'Content-Encoding: none' );

		// No Content-Length. Announcing a length we cannot know invites
		// the connection to be held until the body matches it.
		header_remove( 'Content-Length' );
	}

	/**
	 * Remove every output buffer above us.
	 *
	 * Contents are discarded rather than flushed. Anything sitting in a
	 * buffer at this point is output nobody asked for, and emitting it
	 * ahead of the first frame would make every frame after it
	 * unparseable — a corrupted stream is worse than a lost notice.
	 *
	 * @return int Bytes discarded.
	 */
	private function tearDownBuffers(): int {
		$discarded = 0;

		while ( ob_get_level() > 0 ) {
			$length = ob_get_length();

			// A handler installed without the removable flag cannot be
			// closed. Looping on it would hang the request, so we stop and
			// let StreamEnvironment report the degraded transport.
			if ( ! @ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			}

			$discarded += false === $length ? 0 : $length;
		}

		return $discarded;
	}

	/**
	 * Write bytes to the client.
	 *
	 * @param string $bytes Already-framed output.
	 * @return void
	 */
	private function push( string $bytes ): void {
		// Not escaped, and must not be: these bytes are SSE frames whose
		// payloads were JSON-encoded by SseEncoder. Running them through
		// an HTML escaper would rewrite the quotes and produce a stream
		// that parses as nothing.
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$this->lastWrite = $this->clock();
	}

	/**
	 * Push whatever PHP is still holding.
	 *
	 * @return void
	 */
	private function flush(): void {
		if ( ob_get_level() > 0 ) {
			@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		flush();
	}

	/**
	 * Monotonic-ish seconds, for pacing only.
	 *
	 * @return float
	 */
	private function clock(): float {
		return microtime( true );
	}
}
