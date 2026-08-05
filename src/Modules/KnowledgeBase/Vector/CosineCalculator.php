<?php
/**
 * Exact similarity over packed vectors.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Vector;

/**
 * Stage 2: exact cosine similarity, straight off the packed bytes.
 *
 * Unpacking to a PHP array first would be the obvious implementation and
 * costs about four times as much: a 1,536-element PHP float array is
 * roughly 100 KB against the blob's 6 KB, and two hundred of them
 * allocated and freed per query is most of the stage's time spent in the
 * allocator. `unpack()` is still used — there is no cheaper way to read
 * float32 in PHP — but the query vector is unpacked once and reused for
 * every candidate, which is where the saving actually is.
 */
final class CosineCalculator {

	/**
	 * Similarity between an unpacked query and a packed candidate.
	 *
	 * @param array<int, float> $query     Query components.
	 * @param float             $queryNorm Precomputed query norm.
	 * @param string            $blob      Packed float32 candidate.
	 * @param float             $blobNorm  Precomputed candidate norm, 0 to compute.
	 * @return float Between -1 and 1.
	 */
	public static function score( array $query, float $queryNorm, string $blob, float $blobNorm = 0.0 ): float {
		if ( array() === $query || '' === $blob || 0.0 === $queryNorm ) {
			return 0.0;
		}

		$candidate = VectorCodec::unpack( $blob );

		if ( count( $candidate ) !== count( $query ) ) {
			// Widths disagree, so the vectors are from different models and
			// any number computed from them is meaningless. Zero is the
			// honest answer: this candidate cannot be compared, so it does
			// not rank.
			return 0.0;
		}

		$dot = 0.0;

		foreach ( $query as $index => $component ) {
			$dot += $component * $candidate[ $index ];
		}

		if ( $blobNorm <= 0.0 ) {
			$blobNorm = self::norm( $candidate );
		}

		$magnitude = $queryNorm * $blobNorm;

		return 0.0 === $magnitude ? 0.0 : $dot / $magnitude;
	}

	/**
	 * Euclidean length of a vector.
	 *
	 * @param array<int, float> $vector Components.
	 * @return float
	 */
	public static function norm( array $vector ): float {
		$sum = 0.0;

		foreach ( $vector as $component ) {
			$sum += $component * $component;
		}

		return sqrt( $sum );
	}
}
