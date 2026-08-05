<?php
/**
 * The result of one embedding call.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

use Hiveclerk\Domain\Knowledge\Embedding;

/**
 * Vectors for one batch of inputs, in the order they were sent.
 *
 * Order is the contract. The caller holds chunk ids in a parallel array
 * and matches them back by position, because providers return vectors
 * without echoing the text. A provider that reorders its response — or an
 * adapter that reads an `index` field carelessly — attaches every vector
 * to the wrong chunk, and nothing downstream can tell: retrieval simply
 * becomes wrong. The adapters sort by the provider's own index for
 * exactly this reason, and the count is checked before the batch is used.
 */
final class EmbeddingBatch {

	/**
	 * Construct.
	 *
	 * @param array<int, array<int, float>> $vectors   Vectors, in input order.
	 * @param string                        $provider  Provider identifier.
	 * @param string                        $model     Model identifier.
	 * @param int                           $tokensIn  Tokens billed, 0 when unreported.
	 * @param int                           $latencyMs Round trip.
	 */
	public function __construct(
		public readonly array $vectors,
		public readonly string $provider,
		public readonly string $model,
		public readonly int $tokensIn = 0,
		public readonly int $latencyMs = 0
	) {
	}

	/**
	 * How many vectors came back.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->vectors );
	}

	/**
	 * Width of the first vector, 0 when empty.
	 *
	 * @return int
	 */
	public function dimensions(): int {
		$first = $this->vectors[0] ?? array();

		return count( $first );
	}

	/**
	 * The vectors as domain embeddings.
	 *
	 * @return array<int, Embedding>
	 */
	public function embeddings(): array {
		return array_map(
			fn ( array $vector ): Embedding => new Embedding( $vector, $this->provider, $this->model ),
			array_values( $this->vectors )
		);
	}

	/**
	 * One vector by position, as a domain embedding.
	 *
	 * @param int $index Position.
	 * @return Embedding|null
	 */
	public function at( int $index ): ?Embedding {
		if ( ! isset( $this->vectors[ $index ] ) ) {
			return null;
		}

		return new Embedding( $this->vectors[ $index ], $this->provider, $this->model );
	}

	/**
	 * The same batch with a measured round trip.
	 *
	 * @param int $latencyMs Milliseconds.
	 * @return self
	 */
	public function withLatency( int $latencyMs ): self {
		return new self( $this->vectors, $this->provider, $this->model, $this->tokensIn, $latencyMs );
	}
}
