<?php
/**
 * Credential check result.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

/**
 * The outcome of checking a key against a provider.
 *
 * A failure is a result, not an exception: the operator pasted a key and
 * wants to know what is wrong with it, so the message has to reach the
 * screen intact rather than being flattened into a 502.
 */
final class Verification implements \JsonSerializable {

	/**
	 * Construct.
	 *
	 * @param bool   $ok         Whether the credentials work.
	 * @param string $message    What to tell the operator.
	 * @param int    $modelCount Models visible to this key.
	 * @param int    $latencyMs  Round-trip time.
	 */
	private function __construct(
		public readonly bool $ok,
		public readonly string $message,
		public readonly int $modelCount = 0,
		public readonly int $latencyMs = 0
	) {
	}

	/**
	 * The key works.
	 *
	 * @param int $modelCount Models visible.
	 * @param int $latencyMs  Round-trip time.
	 * @return self
	 */
	public static function pass( int $modelCount, int $latencyMs = 0 ): self {
		return new self( true, 'Connected.', $modelCount, $latencyMs );
	}

	/**
	 * The key does not work.
	 *
	 * @param string $message Reason, safe to display.
	 * @return self
	 */
	public static function fail( string $message ): self {
		return new self( false, $message );
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'ok'          => $this->ok,
			'message'     => $this->message,
			'model_count' => $this->modelCount,
			'latency_ms'  => $this->latencyMs,
		);
	}
}
