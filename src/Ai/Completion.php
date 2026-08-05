<?php
/**
 * A model's reply.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

/**
 * What came back from a provider, normalised.
 *
 * Token counts come from the provider's own usage block wherever one is
 * returned. Nothing here is estimated: an estimate that reaches the
 * customer's spend report is a wrong number wearing a right number's
 * clothes.
 */
final class Completion implements \JsonSerializable {

	/**
	 * Construct.
	 *
	 * @param string      $text         Assistant text.
	 * @param string      $model        Model that actually served it.
	 * @param string      $provider     Provider identifier.
	 * @param int         $tokensIn     Input tokens as reported.
	 * @param int         $tokensOut    Output tokens as reported.
	 * @param string      $finishReason Why generation stopped.
	 * @param int         $latencyMs    Wall-clock duration.
	 * @param float|null  $reportedCost Provider-supplied cost, when given.
	 */
	public function __construct(
		public readonly string $text,
		public readonly string $model,
		public readonly string $provider,
		public readonly int $tokensIn = 0,
		public readonly int $tokensOut = 0,
		public readonly string $finishReason = 'stop',
		public readonly int $latencyMs = 0,
		public readonly ?float $reportedCost = null
	) {
	}

	/**
	 * Whether the model stopped because it hit the output ceiling.
	 *
	 * The widget shows a truncated reply differently, and the analytics
	 * screen counts these separately: a rising truncation rate means
	 * max_tokens is set too low, not that the clerk is answering badly.
	 *
	 * @return bool
	 */
	public function wasTruncated(): bool {
		return in_array( $this->finishReason, array( 'length', 'max_tokens' ), true );
	}

	/**
	 * The same completion with timing attached.
	 *
	 * @param int $latencyMs Duration.
	 * @return self
	 */
	public function withLatency( int $latencyMs ): self {
		return new self(
			$this->text,
			$this->model,
			$this->provider,
			$this->tokensIn,
			$this->tokensOut,
			$this->finishReason,
			$latencyMs,
			$this->reportedCost
		);
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'text'          => $this->text,
			'model'         => $this->model,
			'provider'      => $this->provider,
			'tokens_in'     => $this->tokensIn,
			'tokens_out'    => $this->tokensOut,
			'finish_reason' => $this->finishReason,
			'latency_ms'    => $this->latencyMs,
			'truncated'     => $this->wasTruncated(),
		);
	}
}
