<?php
/**
 * A request for a model completion.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

use InvalidArgumentException;

/**
 * Everything a provider needs to produce one reply.
 *
 * The system prompt is a separate field rather than a leading turn because
 * the providers genuinely differ: Anthropic takes a top-level `system`,
 * OpenAI takes a system message in the array, and Google takes
 * `systemInstruction`. Modelling it as a turn would push that difference
 * into every caller instead of into the five adapters where it belongs.
 */
final class CompletionRequest {

	public const DEFAULT_MAX_TOKENS = 1024;

	/**
	 * Construct.
	 *
	 * @param string             $model       Provider-native model id.
	 * @param array<int, ChatTurn> $turns     Conversation, oldest first.
	 * @param string             $system      System instruction.
	 * @param int                $maxTokens   Output ceiling.
	 * @param float              $temperature Sampling temperature, 0–1.
	 * @param array<int, string> $stop        Stop sequences.
	 * @param int                $timeout     Seconds before giving up.
	 *
	 * @throws InvalidArgumentException When the request cannot be served.
	 */
	public function __construct(
		public readonly string $model,
		public readonly array $turns,
		public readonly string $system = '',
		public readonly int $maxTokens = self::DEFAULT_MAX_TOKENS,
		public readonly float $temperature = 0.3,
		public readonly array $stop = array(),
		public readonly int $timeout = 60
	) {
		if ( '' === trim( $model ) ) {
			throw new InvalidArgumentException( 'A completion needs a model.' );
		}

		if ( array() === $turns ) {
			throw new InvalidArgumentException( 'A completion needs at least one turn.' );
		}

		if ( $maxTokens < 1 ) {
			throw new InvalidArgumentException( 'maxTokens must be positive.' );
		}
	}

	/**
	 * The same request against a different model.
	 *
	 * Used by the verify endpoint, which sends a deliberately tiny probe.
	 *
	 * @param string $model Model id.
	 * @return self
	 */
	public function withModel( string $model ): self {
		return new self(
			$model,
			$this->turns,
			$this->system,
			$this->maxTokens,
			$this->temperature,
			$this->stop,
			$this->timeout
		);
	}

	/**
	 * Rough input size, for a budget check before the call is made.
	 *
	 * Four characters per token is the well-known approximation for
	 * English. It is not accurate enough to bill on — that is what the
	 * provider's own usage figures are for — but it is accurate enough to
	 * refuse a prompt that would obviously overflow the context window,
	 * which is the only decision made before the response exists.
	 *
	 * @return int
	 */
	public function approximateInputTokens(): int {
		$characters = strlen( $this->system );

		foreach ( $this->turns as $turn ) {
			$characters += strlen( $turn->content );
		}

		return (int) ceil( $characters / 4 );
	}
}
