<?php
/**
 * Retrieval parameters.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * What a caller may vary about a search.
 *
 * A value object rather than six loose arguments because the two integers
 * — how many candidates the coarse pass keeps, and how many results come
 * back — are trivially transposable and the mistake is silent: the search
 * still works, it is just much worse.
 */
final class RetrievalOptions {

	/**
	 * Results returned when the caller does not say.
	 */
	public const DEFAULT_TOP_K = 5;

	/**
	 * Candidates the coarse pass keeps.
	 *
	 * Two hundred is the figure binary quantisation's ~95% recall claim is
	 * quoted at. Stage 2 re-ranks exactly, so the coarse pass only has to
	 * get the right chunks *into* the set — its ordering is discarded.
	 */
	public const DEFAULT_CANDIDATES = 200;

	/**
	 * Below this many chunks, the coarse pass is skipped entirely.
	 *
	 * Exact cosine over a few hundred vectors is faster than loading a
	 * cached matrix and quantising a query. The scaling ladder in the
	 * architecture calls this the Free-tier path; it is really just the
	 * point where the optimisation stops paying for itself.
	 */
	public const EXACT_SCAN_LIMIT = 500;

	/**
	 * Default cosine floor for treating a match as usable.
	 */
	public const DEFAULT_THRESHOLD = 0.62;

	/**
	 * Construct.
	 *
	 * @param int   $topK       Results to return.
	 * @param int   $candidates Coarse-pass survivors.
	 * @param float $threshold  Cosine floor for confidence.
	 * @param bool  $useKeyword Whether to fuse with FULLTEXT keyword search.
	 * @param bool  $useCache   Whether an identical query may be served from cache.
	 */
	public function __construct(
		public readonly int $topK = self::DEFAULT_TOP_K,
		public readonly int $candidates = self::DEFAULT_CANDIDATES,
		public readonly float $threshold = self::DEFAULT_THRESHOLD,
		public readonly bool $useKeyword = true,
		public readonly bool $useCache = true
	) {
	}

	/**
	 * Build from request input, clamped to sane bounds.
	 *
	 * @param int|null   $topK       Requested result count.
	 * @param float|null $threshold  Requested confidence floor.
	 * @param bool       $useKeyword Whether to fuse keyword results.
	 * @param bool       $useCache   Whether the result cache may be used.
	 * @return self
	 */
	public static function of(
		?int $topK = null,
		?float $threshold = null,
		bool $useKeyword = true,
		bool $useCache = true
	): self {
		$k = max( 1, min( 50, $topK ?? self::DEFAULT_TOP_K ) );

		return new self(
			topK: $k,
			// Always at least ten candidates per result. A top-50 request
			// against a 200-candidate pass would be re-ranking a quarter of
			// its own output, which defeats the point of two stages.
			candidates: max( self::DEFAULT_CANDIDATES, $k * 10 ),
			threshold: max( 0.0, min( 1.0, $threshold ?? self::DEFAULT_THRESHOLD ) ),
			useKeyword: $useKeyword,
			useCache: $useCache
		);
	}
}
