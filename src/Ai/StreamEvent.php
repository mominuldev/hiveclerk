<?php
/**
 * One event from a streaming completion.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

/**
 * A normalised streaming event.
 *
 * The five providers emit wildly different frame vocabularies —
 * `content_block_delta`, `chat.completion.chunk`, bare JSON arrays. Each
 * adapter translates into these three cases, so the transport layer in
 * Sprint 5 has one shape to forward and the widget has one shape to read.
 */
final class StreamEvent {

	public const DELTA = 'delta';
	public const DONE  = 'done';
	public const ERROR = 'error';

	/**
	 * Construct.
	 *
	 * @param string          $type       One of the class constants.
	 * @param string          $text       Incremental text for a delta.
	 * @param Completion|null $completion Final result, on done.
	 * @param string          $message    Operator-facing text, on error.
	 * @param bool            $retryable  Whether a retry could succeed.
	 */
	private function __construct(
		public readonly string $type,
		public readonly string $text = '',
		public readonly ?Completion $completion = null,
		public readonly string $message = '',
		public readonly bool $retryable = false
	) {
	}

	/**
	 * More text arrived.
	 *
	 * @param string $text Increment.
	 * @return self
	 */
	public static function delta( string $text ): self {
		return new self( self::DELTA, $text );
	}

	/**
	 * Generation finished.
	 *
	 * @param Completion $completion Final result with usage.
	 * @return self
	 */
	public static function done( Completion $completion ): self {
		return new self( self::DONE, '', $completion );
	}

	/**
	 * Generation failed part-way.
	 *
	 * A stream can fail after text has already been shown, so this is an
	 * event rather than an exception: the caller needs to close the
	 * partial reply cleanly, not unwind as though nothing was sent.
	 *
	 * @param string $message   Operator-facing text.
	 * @param bool   $retryable Whether a retry could succeed.
	 * @return self
	 */
	public static function error( string $message, bool $retryable = false ): self {
		return new self( self::ERROR, '', null, $message, $retryable );
	}
}
