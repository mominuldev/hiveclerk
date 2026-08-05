<?php
/**
 * Server-sent event framing.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Api\Streaming;

/**
 * Turns names and payloads into wire frames.
 *
 * Kept separate from the stream that writes them so the framing can be
 * tested without an HTTP connection — and, more usefully, tested against
 * the parser we already ship: anything this encodes, SseParser must
 * decode back to the same name and payload. The two are written from the
 * same specification, and a round trip is the only check that says so.
 *
 * Every method is static and pure. There is no state to get wrong.
 */
final class SseEncoder {

	/**
	 * Frame terminator.
	 *
	 * Two newlines, always \n. The specification permits \r\n, but a
	 * mixed-ending stream is the kind of thing that works everywhere
	 * except one proxy, so we emit exactly one form.
	 */
	private const END = "\n\n";

	/**
	 * Encode a named event carrying JSON.
	 *
	 * @param string              $event Event name.
	 * @param array<string,mixed> $data  Payload.
	 * @return string
	 */
	public static function event( string $event, array $data ): string {
		$json = wp_json_encode( $data );

		return self::raw( $event, false === $json ? '{}' : $json );
	}

	/**
	 * Encode a named event carrying an already-serialised payload.
	 *
	 * @param string $event Event name.
	 * @param string $data  Payload.
	 * @return string
	 */
	public static function raw( string $event, string $data ): string {
		return 'event: ' . self::sanitiseName( $event ) . "\n" . self::dataLines( $data ) . self::END;
	}

	/**
	 * Encode a comment.
	 *
	 * Comments are the specification's own keep-alive: a receiver ignores
	 * them, but they are bytes, and bytes are what prove a connection is
	 * still there and what push a reluctant proxy into flushing.
	 *
	 * @param string $text Comment text.
	 * @return string
	 */
	public static function comment( string $text ): string {
		return ': ' . str_replace( array( "\r", "\n" ), ' ', $text ) . self::END;
	}

	/**
	 * Encode a retry hint.
	 *
	 * Tells EventSource how long to wait before reconnecting. We set it
	 * high deliberately: an automatic reconnect would start a second
	 * completion and bill the customer twice for one answer.
	 *
	 * @param int $milliseconds Delay.
	 * @return string
	 */
	public static function retry( int $milliseconds ): string {
		return 'retry: ' . max( 0, $milliseconds ) . self::END;
	}

	/**
	 * Split a payload across data lines.
	 *
	 * A newline inside the payload would otherwise terminate the field.
	 * The specification's answer is one data line per payload line, which
	 * the receiver rejoins with newlines — so the split is lossless as
	 * long as both sides follow it.
	 *
	 * @param string $data Payload.
	 * @return string
	 */
	private static function dataLines( string $data ): string {
		$normalised = str_replace( array( "\r\n", "\r" ), "\n", $data );
		$lines      = explode( "\n", $normalised );

		return implode( "\n", array_map( static fn ( string $line ): string => 'data: ' . $line, $lines ) );
	}

	/**
	 * Reduce an event name to something that cannot break framing.
	 *
	 * Event names come from our own code, not from input — but a name
	 * containing a newline would silently split one frame into two, and a
	 * defect that only appears as a truncated answer is expensive to find.
	 *
	 * @param string $event Event name.
	 * @return string
	 */
	private static function sanitiseName( string $event ): string {
		$clean = preg_replace( '/[^A-Za-z0-9._-]/', '', $event );

		return null === $clean || '' === $clean ? 'message' : $clean;
	}
}
