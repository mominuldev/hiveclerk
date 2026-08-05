<?php
/**
 * The outcome of one retrieval.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * Results and the diagnostics that produced them, together.
 *
 * Kept as one object because every caller needs both. The chat path
 * records the timings against the conversation so a slow answer can be
 * explained afterwards; the playground displays them; the gaps detector
 * reads the confidence count to decide whether a question went
 * unanswered. Returning only the chunks would push that data into a
 * second call that no longer knows which search it is describing.
 */
final class RetrievalResult {

	/**
	 * Construct.
	 *
	 * @param array<int, RetrievedChunk> $chunks      Ordered results.
	 * @param RetrievalDiagnostics       $diagnostics Timings and counts.
	 */
	public function __construct(
		public readonly array $chunks,
		public readonly RetrievalDiagnostics $diagnostics
	) {
	}

	/**
	 * Whether anything came back.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return array() === $this->chunks;
	}

	/**
	 * Only the results at or above a confidence threshold.
	 *
	 * @param float $threshold Minimum cosine similarity.
	 * @return array<int, RetrievedChunk>
	 */
	public function confident( float $threshold ): array {
		return array_values(
			array_filter(
				$this->chunks,
				static fn ( RetrievedChunk $chunk ): bool => $chunk->isConfident( $threshold )
			)
		);
	}

	/**
	 * The best cosine similarity found.
	 *
	 * The number the knowledge-gaps report is built on: a question whose
	 * best match scored 0.21 was not answered from the knowledge base, and
	 * saying so is more useful than saying the clerk declined.
	 *
	 * @return float
	 */
	public function bestScore(): float {
		$best = 0.0;

		foreach ( $this->chunks as $chunk ) {
			$best = max( $best, $chunk->vectorScore );
		}

		return $best;
	}
}
