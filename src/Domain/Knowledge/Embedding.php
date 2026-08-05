<?php
/**
 * Embedding value object.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * One vector, and which model produced it.
 *
 * The provider and model travel with the numbers on purpose. Vectors from
 * two different models occupy unrelated spaces, and a cosine similarity
 * between them is a real number with no meaning — it does not error, it
 * just ranks nonsense highly. Carrying the provenance is what lets the
 * store refuse the comparison instead of performing it.
 */
final class Embedding {

	/**
	 * Cached L2 norm.
	 *
	 * @var float|null
	 */
	private ?float $norm = null;

	/**
	 * Construct.
	 *
	 * @param array<int, float> $vector   The components.
	 * @param string            $provider Provider identifier.
	 * @param string            $model    Model identifier.
	 */
	public function __construct(
		public readonly array $vector,
		public readonly string $provider = '',
		public readonly string $model = ''
	) {
	}

	/**
	 * Vector width.
	 *
	 * @return int
	 */
	public function dimensions(): int {
		return count( $this->vector );
	}

	/**
	 * Whether this carries no components.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return array() === $this->vector;
	}

	/**
	 * Whether two embeddings are comparable.
	 *
	 * @param Embedding $other Other embedding.
	 * @return bool
	 */
	public function matches( Embedding $other ): bool {
		return $this->provider === $other->provider
			&& $this->model === $other->model
			&& $this->dimensions() === $other->dimensions();
	}

	/**
	 * Euclidean length, computed once.
	 *
	 * @return float
	 */
	public function norm(): float {
		if ( null === $this->norm ) {
			$sum = 0.0;

			foreach ( $this->vector as $component ) {
				$sum += $component * $component;
			}

			$this->norm = sqrt( $sum );
		}

		return $this->norm;
	}

	/**
	 * Cosine similarity against another vector.
	 *
	 * Returns 0.0 rather than dividing by zero for a zero-length vector,
	 * which a provider can legitimately return for an input that
	 * normalised to nothing. Zero is the correct answer — it is similar to
	 * nothing — and it keeps a single degenerate chunk from taking the
	 * whole query down with a division error.
	 *
	 * @param Embedding $other Other embedding.
	 * @return float Between -1 and 1.
	 */
	public function cosine( Embedding $other ): float {
		$magnitude = $this->norm() * $other->norm();

		if ( 0.0 === $magnitude ) {
			return 0.0;
		}

		$dot   = 0.0;
		$right = $other->vector;

		foreach ( $this->vector as $index => $component ) {
			if ( isset( $right[ $index ] ) ) {
				$dot += $component * $right[ $index ];
			}
		}

		return $dot / $magnitude;
	}
}
