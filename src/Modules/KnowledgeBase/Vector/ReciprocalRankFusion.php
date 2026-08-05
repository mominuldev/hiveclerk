<?php
/**
 * Rank fusion.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Vector;

/**
 * Combines several ranked lists into one, using only their orderings.
 *
 * The alternative — normalise each signal's scores and add them — is
 * where hybrid search usually goes wrong. A cosine similarity is bounded
 * to [-1, 1] and clusters tightly in the top results; MySQL's FULLTEXT
 * relevance is unbounded and its scale moves with corpus size and term
 * frequency. Any mapping between them is a weighting decision disguised
 * as arithmetic, and it silently re-weights itself as the customer's
 * content grows.
 *
 * Reciprocal rank fusion needs no such mapping. Each list contributes
 * `1 / (k + rank)` and nothing else, so the two signals never have to be
 * made commensurable — only ordered, which they already are.
 */
final class ReciprocalRankFusion {

	/**
	 * The rank-decay constant.
	 *
	 * The value from the original paper. It sets how much a rank advantage
	 * is worth: at 60, first place beats second by a few percent while
	 * tenth still meaningfully beats fiftieth. A much smaller constant
	 * makes whichever signal ranked something first dominate the result,
	 * which reintroduces exactly the single-signal failure fusion exists
	 * to avoid.
	 */
	public const DEFAULT_K = 60;

	/**
	 * Fuse ranked id lists into one ordered map.
	 *
	 * @param array<int, array<int, int>> $lists   Lists of ids, each best first.
	 * @param array<int, float>           $weights Per-list weight, defaulting to 1.
	 * @param int                         $k       Decay constant.
	 * @return array<int, float> Id to fused score, best first.
	 */
	public static function fuse( array $lists, array $weights = array(), int $k = self::DEFAULT_K ): array {
		$fused = array();

		foreach ( array_values( $lists ) as $listIndex => $ids ) {
			$weight = $weights[ $listIndex ] ?? 1.0;

			foreach ( array_values( $ids ) as $position => $id ) {
				$id = (int) $id;

				// Guard the denominator rather than trusting the caller.
				// A negative k would produce a division by zero at one
				// specific rank, which is the kind of bug that only shows
				// up on the corpus where that rank is reached.
				$denominator = max( 1, $k + $position + 1 );

				$fused[ $id ] = ( $fused[ $id ] ?? 0.0 ) + $weight / $denominator;
			}
		}

		// arsort() keeps insertion order among equal scores, which makes
		// the output deterministic for a given input. Two chunks tying is
		// common — it happens whenever both appear at the same rank in one
		// list and in neither of the others — and a result order that
		// changes between identical searches reads as a broken index.
		arsort( $fused );

		return $fused;
	}

	/**
	 * Rank positions for a list of ids, from 1.
	 *
	 * @param array<int, int> $ids Ids, best first.
	 * @return array<int, int> Id to rank.
	 */
	public static function ranks( array $ids ): array {
		$ranks = array();

		foreach ( array_values( $ids ) as $position => $id ) {
			$id = (int) $id;

			// First occurrence wins. A duplicate id later in the same list
			// is a worse position for the same thing, and recording it
			// would report a rank the item never actually held.
			if ( ! isset( $ranks[ $id ] ) ) {
				$ranks[ $id ] = $position + 1;
			}
		}

		return $ranks;
	}
}
