<?php
/**
 * Server-sent events parser.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai\Streaming;

/**
 * Incrementally parses a text/event-stream body.
 *
 * Written by hand rather than pulled in, because the one thing that
 * matters here is behaviour at chunk boundaries: a TCP chunk splits
 * wherever it likes, routinely mid-field and occasionally mid-UTF-8
 * character. A parser that assumes each chunk is a whole frame works in
 * development and drops tokens in production under exactly the network
 * conditions that are hardest to reproduce.
 *
 * Implements the fields the model providers actually use — `event`, `data`
 * and comment lines — and ignores `id` and `retry`, which none of them
 * send and neither of which we would act on.
 */
final class SseParser {

	/**
	 * Bytes received but not yet forming a complete line.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Feed a chunk and take whatever complete frames it produced.
	 *
	 * @param string $chunk Raw bytes.
	 * @return array<int, SseFrame>
	 */
	public function feed( string $chunk ): array {
		$this->buffer .= $chunk;
		$frames        = array();

		// Normalise CRLF and lone CR before splitting: providers behind
		// different proxies are not consistent about line endings, and a
		// stray \r left on the end of a field name silently stops it
		// matching "data".
		$this->buffer = str_replace( array( "\r\n", "\r" ), "\n", $this->buffer );

		while ( true ) {
			$boundary = strpos( $this->buffer, "\n\n" );

			if ( false === $boundary ) {
				break;
			}

			$block        = substr( $this->buffer, 0, $boundary );
			$this->buffer = substr( $this->buffer, $boundary + 2 );

			$frame = self::parseBlock( $block );

			if ( null !== $frame ) {
				$frames[] = $frame;
			}
		}

		return $frames;
	}

	/**
	 * Take any frame left in the buffer at end of stream.
	 *
	 * A well-behaved server terminates the last frame with a blank line.
	 * Not all of them do, and a dropped final frame means a lost usage
	 * block — the numbers the whole cost report is built on.
	 *
	 * @return array<int, SseFrame>
	 */
	public function flush(): array {
		$remaining    = trim( $this->buffer );
		$this->buffer = '';

		if ( '' === $remaining ) {
			return array();
		}

		$frame = self::parseBlock( $remaining );

		return null === $frame ? array() : array( $frame );
	}

	/**
	 * Parse one frame block.
	 *
	 * @param string $block Text between blank lines.
	 * @return SseFrame|null Null for comment-only or empty blocks.
	 */
	private static function parseBlock( string $block ): ?SseFrame {
		$event = '';
		$data  = array();

		foreach ( explode( "\n", $block ) as $line ) {
			// A line starting with ':' is a comment. Providers send these
			// as keep-alives; they carry no payload.
			if ( '' === $line || str_starts_with( $line, ':' ) ) {
				continue;
			}

			$colon = strpos( $line, ':' );

			if ( false === $colon ) {
				continue;
			}

			$field = substr( $line, 0, $colon );
			$value = substr( $line, $colon + 1 );

			// Exactly one leading space is part of the framing, not the
			// value. Stripping all whitespace would corrupt indented JSON.
			if ( str_starts_with( $value, ' ' ) ) {
				$value = substr( $value, 1 );
			}

			if ( 'event' === $field ) {
				$event = $value;
			} elseif ( 'data' === $field ) {
				$data[] = $value;
			}
		}

		if ( array() === $data && '' === $event ) {
			return null;
		}

		// Multiple data lines in one frame join with newlines, per spec.
		return new SseFrame( $event, implode( "\n", $data ) );
	}
}
