<?php
/**
 * Partial-reply store for the polling transport.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Streaming;

/**
 * Holds a reply as it is generated so a second request can read it.
 *
 * On a host that buffers, the streaming endpoint delivers a perfectly
 * correct answer all at once at the end, which is a slow answer. The
 * fallback splits the work across two requests: one generates and writes
 * here, another polls and reads. This class is the only thing between
 * them.
 *
 * ## Why the writes are throttled
 *
 * The obvious implementation writes after every token. With a persistent
 * object cache that is merely wasteful; without one it is a database
 * write per token, and a 300-token answer becomes 300 option updates —
 * slower than not streaming at all, on exactly the hosts that cannot
 * stream. So writes are coalesced: at most one every {@see FLUSH_MS}
 * milliseconds, plus one for every terminal event, which is the resolution
 * a reader perceives as continuous anyway.
 *
 * ## Why the payload is base64
 *
 * Sprint 4 established this the hard way. Without a persistent object
 * cache the payload lands in a transient, which is an option row, which is
 * a `utf8mb4 LONGTEXT` column — and `wpdb::strip_invalid_text_for_column()`
 * silently removes byte sequences that are not valid UTF-8. A reply
 * accumulates one provider delta at a time and a delta may split a
 * multibyte character, so the stored string is transiently invalid by
 * construction. The write reports success and the read reports a miss.
 * Encoding first makes the payload ASCII and the question moot.
 */
final class StreamBuffer {

	/**
	 * Object cache group.
	 */
	private const GROUP = 'hiveclerk_chat';

	/**
	 * Transient prefix for the fallback path.
	 */
	private const TRANSIENT_PREFIX = 'hvc_buf_';

	/**
	 * How long a buffer survives.
	 *
	 * Longer than any completion and shorter than a session. A visitor who
	 * navigates away mid-answer leaves one behind, and it must not sit in
	 * the options table until the site is next cleaned.
	 */
	private const TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Minimum milliseconds between writes.
	 */
	private const FLUSH_MS = 150;

	/**
	 * Buffers written this request, keyed by cache key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $pending = array();

	/**
	 * Last write time per key, in milliseconds.
	 *
	 * @var array<string, float>
	 */
	private array $lastWrite = array();

	/**
	 * Begin a buffer.
	 *
	 * @param string $key Namespaced buffer key.
	 * @return void
	 */
	public function open( string $key ): void {
		$this->pending[ $key ] = array(
			'text'       => '',
			'complete'   => false,
			'citations'  => array(),
			'message_id' => null,
			'error'      => null,
		);

		$this->write( $key, true );
	}

	/**
	 * Append generated text.
	 *
	 * @param string $key  Buffer key.
	 * @param string $text Increment.
	 * @return void
	 */
	public function append( string $key, string $text ): void {
		if ( ! isset( $this->pending[ $key ] ) ) {
			$this->open( $key );
		}

		$this->pending[ $key ]['text'] .= $text;

		$this->write( $key, false );
	}

	/**
	 * Replace everything written so far.
	 *
	 * @param string $key  Buffer key.
	 * @param string $text Replacement.
	 * @return void
	 */
	public function replace( string $key, string $text ): void {
		if ( ! isset( $this->pending[ $key ] ) ) {
			$this->open( $key );
		}

		$this->pending[ $key ]['text'] = $text;

		$this->write( $key, true );
	}

	/**
	 * Attach the reply's citations.
	 *
	 * @param string                           $key       Buffer key.
	 * @param array<int, array<string, mixed>> $citations Citation payloads.
	 * @return void
	 */
	public function citations( string $key, array $citations ): void {
		if ( ! isset( $this->pending[ $key ] ) ) {
			$this->open( $key );
		}

		$this->pending[ $key ]['citations'] = $citations;

		$this->write( $key, true );
	}

	/**
	 * Mark the reply finished.
	 *
	 * @param string               $key     Buffer key.
	 * @param array<string, mixed> $payload Closing metadata.
	 * @return void
	 */
	public function complete( string $key, array $payload ): void {
		if ( ! isset( $this->pending[ $key ] ) ) {
			$this->open( $key );
		}

		$this->pending[ $key ]['complete']   = true;
		$this->pending[ $key ]['message_id'] = $payload['message_id'] ?? null;
		$this->pending[ $key ]['done']       = $payload;

		$this->write( $key, true );
	}

	/**
	 * Record a failure the poller must show.
	 *
	 * @param string $key     Buffer key.
	 * @param string $code    Error code.
	 * @param string $message Visitor-facing text.
	 * @return void
	 */
	public function fail( string $key, string $code, string $message ): void {
		if ( ! isset( $this->pending[ $key ] ) ) {
			$this->open( $key );
		}

		$this->pending[ $key ]['complete'] = true;
		$this->pending[ $key ]['error']    = array(
			'code'    => $code,
			'message' => $message,
		);

		$this->write( $key, true );
	}

	/**
	 * Read a buffer.
	 *
	 * @param string $key Buffer key.
	 * @return array<string, mixed>|null
	 */
	public function read( string $key ): ?array {
		// No `$found` out-parameter here, unlike MatrixCache. What is stored
		// is always a non-empty base64 string, so "not a string" and "not
		// found" are the same answer — and asking the simpler question keeps
		// the miss path identical under a drop-in cache that does not
		// implement the fourth argument.
		$cached = wp_cache_get( $key, self::GROUP );

		if ( ! is_string( $cached ) || '' === $cached ) {
			$cached = get_transient( self::TRANSIENT_PREFIX . $key );
		}

		if ( ! is_string( $cached ) || '' === $cached ) {
			return null;
		}

		$decoded = base64_decode( $cached, true );

		if ( false === $decoded ) {
			return null;
		}

		$payload = json_decode( $decoded, true );

		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Drop a buffer.
	 *
	 * @param string $key Buffer key.
	 * @return void
	 */
	public function forget( string $key ): void {
		unset( $this->pending[ $key ], $this->lastWrite[ $key ] );

		wp_cache_delete( $key, self::GROUP );
		delete_transient( self::TRANSIENT_PREFIX . $key );
	}

	/**
	 * Build the key for one generation.
	 *
	 * Namespaced by session, which is what makes the client-supplied
	 * reference safe: a caller can only ever address buffers belonging to
	 * the session they already hold a token for.
	 *
	 * @param string $sessionUuid Session identifier.
	 * @param string $reference   Client-supplied generation reference.
	 * @return string
	 */
	public static function key( string $sessionUuid, string $reference ): string {
		return 'hvc_' . hash( 'sha256', $sessionUuid . '|' . $reference );
	}

	/**
	 * Persist the current state, subject to the throttle.
	 *
	 * @param string $key   Buffer key.
	 * @param bool   $force Write regardless of the throttle.
	 * @return void
	 */
	private function write( string $key, bool $force ): void {
		$now = microtime( true ) * 1000;

		if ( ! $force && isset( $this->lastWrite[ $key ] ) && $now - $this->lastWrite[ $key ] < self::FLUSH_MS ) {
			return;
		}

		$this->lastWrite[ $key ] = $now;

		$state = $this->pending[ $key ] ?? array();
		$json  = wp_json_encode( $state );

		if ( false === $json ) {
			return;
		}

		$encoded = base64_encode( $json );

		wp_cache_set( $key, $encoded, self::GROUP, self::TTL );

		if ( ! wp_using_ext_object_cache() ) {
			set_transient( self::TRANSIENT_PREFIX . $key, $encoded, self::TTL );
		}
	}
}
