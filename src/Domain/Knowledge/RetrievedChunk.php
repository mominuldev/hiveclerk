<?php
/**
 * A chunk that survived retrieval, with its scores.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * One result, carrying every score that produced its position.
 *
 * All three scores are kept rather than only the one that decided the
 * ordering. Retrieval is the part of this product that fails most
 * mysteriously, and "why is this third" is unanswerable from a single
 * fused number — the useful answer is almost always that one signal
 * disagreed with the other, which is only visible if both survive.
 */
final class RetrievedChunk {

	/**
	 * Construct.
	 *
	 * @param Chunk       $chunk         The chunk itself.
	 * @param float       $vectorScore   Exact cosine similarity, -1 to 1.
	 * @param float       $bm25Score     MySQL FULLTEXT relevance, unbounded.
	 * @param float       $fusedScore    Reciprocal rank fusion score.
	 * @param int         $rank          Final position, from 1.
	 * @param int|null    $vectorRank    Rank in the vector list, null when absent.
	 * @param int|null    $keywordRank   Rank in the keyword list, null when absent.
	 * @param string      $documentTitle Title of the owning document.
	 * @param string|null $documentUrl   URL of the owning document.
	 */
	public function __construct(
		public readonly Chunk $chunk,
		public readonly float $vectorScore,
		public readonly float $bm25Score,
		public readonly float $fusedScore,
		public readonly int $rank,
		public readonly ?int $vectorRank = null,
		public readonly ?int $keywordRank = null,
		public readonly string $documentTitle = '',
		public readonly ?string $documentUrl = null
	) {
	}

	/**
	 * Whether this match is confident enough to answer from.
	 *
	 * Judged on the cosine score, not the fused one. Fusion decides
	 * ordering by combining two ranks and its output is not a similarity —
	 * a chunk ranked first by both signals scores highly even when neither
	 * signal thought it was a good match. The number that answers "is this
	 * actually about the question" is the cosine, and that is the number a
	 * threshold has to gate.
	 *
	 * @param float $threshold Minimum cosine similarity.
	 * @return bool
	 */
	public function isConfident( float $threshold ): bool {
		return $this->vectorScore >= $threshold;
	}

	/**
	 * A short quotation for a citation chip or a debug list.
	 *
	 * @param int $length Maximum characters.
	 * @return string
	 */
	public function excerpt( int $length = 240 ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', $this->chunk->content ) ?? $this->chunk->content );

		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}

		return rtrim( mb_substr( $text, 0, $length ) ) . '…';
	}

	/**
	 * A copy of this result at a different rank.
	 *
	 * @param int $rank New rank.
	 * @return self
	 */
	public function atRank( int $rank ): self {
		return new self(
			$this->chunk,
			$this->vectorScore,
			$this->bm25Score,
			$this->fusedScore,
			$rank,
			$this->vectorRank,
			$this->keywordRank,
			$this->documentTitle,
			$this->documentUrl
		);
	}
}
