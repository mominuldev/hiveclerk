<?php
/**
 * Accumulated state of an in-flight stream.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai\Streaming;

use Hiveclerk\Ai\Completion;

/**
 * Collects what a stream has produced so far.
 *
 * Mutable on purpose. Every other object in this layer is immutable, but a
 * stream is a running total by nature, and threading a rebuilt value
 * object through a per-chunk callback would allocate once per token for no
 * benefit.
 *
 * Providers report usage at different moments — Anthropic splits it across
 * the opening and closing frames, OpenAI attaches it to a final chunk only
 * when asked — so both counts are set independently and whatever is known
 * at the end is what gets recorded.
 */
final class StreamState {

	/**
	 * Text assembled from deltas.
	 *
	 * @var string
	 */
	public string $text = '';

	/**
	 * Input tokens, when the provider has said.
	 *
	 * @var int
	 */
	public int $tokensIn = 0;

	/**
	 * Output tokens, when the provider has said.
	 *
	 * @var int
	 */
	public int $tokensOut = 0;

	/**
	 * Why generation stopped.
	 *
	 * @var string
	 */
	public string $finishReason = 'stop';

	/**
	 * Whether a terminal frame has been seen.
	 *
	 * @var bool
	 */
	public bool $finished = false;

	/**
	 * Construct.
	 *
	 * @param string $provider Provider identifier.
	 * @param string $model    Model requested.
	 */
	public function __construct(
		public readonly string $provider,
		public string $model
	) {
	}

	/**
	 * Append a text delta.
	 *
	 * @param string $text Increment.
	 * @return void
	 */
	public function append( string $text ): void {
		$this->text .= $text;
	}

	/**
	 * Build the final completion.
	 *
	 * @param int        $latencyMs    Wall-clock duration.
	 * @param float|null $reportedCost Provider-supplied cost, when given.
	 * @return Completion
	 */
	public function toCompletion( int $latencyMs = 0, ?float $reportedCost = null ): Completion {
		return new Completion(
			$this->text,
			$this->model,
			$this->provider,
			$this->tokensIn,
			$this->tokensOut,
			$this->finishReason,
			$latencyMs,
			$reportedCost
		);
	}
}
