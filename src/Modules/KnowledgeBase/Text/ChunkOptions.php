<?php
/**
 * Chunking parameters.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Text;

/**
 * How large chunks should be and how much they should overlap.
 *
 * FR-KB-06 requires these to be configurable, so they are a value object
 * rather than constants. The defaults come from §4 of the system
 * architecture: roughly 800 tokens with 15% overlap.
 */
final class ChunkOptions {

	public const DEFAULT_MAX_TOKENS = 800;
	public const DEFAULT_OVERLAP    = 0.15;

	/**
	 * Chunk size to aim for, as opposed to the size never to exceed.
	 *
	 * These are two different numbers and conflating them cost the M1
	 * recall gate. 800 is an *embedding* limit — it is about what the
	 * provider's endpoint accepts. Retrieval wants something much smaller,
	 * because a chunk is retrieved whole: a page with five topics packed
	 * into one 800-token chunk has one vector that is the average of five
	 * things and a close match for none of them.
	 *
	 * Measured, not guessed. The same twelve pages of prose score
	 * recall@5 0.889 when each is a single chunk and 0.944 when sub-
	 * headings break them into five, and the only difference is where the
	 * boundaries fall. A page that happens to carry `h2`s got the good
	 * number for free; every flat page got the bad one. This is the
	 * boundary for pages that do not carry their own.
	 */
	public const DEFAULT_TARGET_TOKENS = 200;

	/**
	 * Smallest chunk worth storing on its own, in tokens.
	 *
	 * A 20-token trailing chunk is a sentence fragment with a vector of
	 * its own. It competes for retrieval slots against chunks that
	 * actually answer something, and usually wins on short queries
	 * because a short text matches a short query. Fragments are folded
	 * back into the chunk before them instead.
	 */
	public const DEFAULT_MIN_TOKENS = 48;

	/**
	 * Ceiling on the budget.
	 *
	 * Embedding endpoints reject inputs above their own limit — 8192
	 * tokens for the common models — and the estimate is only an
	 * estimate, so the ceiling leaves room for it to be wrong.
	 */
	public const ABSOLUTE_MAX_TOKENS = 4000;

	/**
	 * Smallest target a stored configuration may ask for.
	 *
	 * A target is a divisor on a page: halve it and you roughly double the
	 * chunk count, and every chunk is an embedding call the customer pays
	 * for. Left unbounded, `chunk_target: 1` turns one page into a chunk
	 * per sentence and one re-index into a bill — reachable by anyone who
	 * can write a source, which includes roles deliberately not trusted
	 * with the API key itself. Cost exhaustion is cheaper to execute than
	 * a denial of service and harder to notice.
	 *
	 * 64 matches the floor already applied to the ceiling, below which the
	 * two bounds would cross. It is also below anything measured as
	 * useful: 128 scored recall@5 0.741 against 0.926 at the 200 default,
	 * so this floor is well past the point of being a bad idea and exists
	 * only to stop the pathological case.
	 */
	public const MIN_TARGET_TOKENS = 64;

	/**
	 * Size to aim for when packing units into a chunk.
	 *
	 * Not promoted, because it is clamped against `$maxTokens` and a
	 * promoted property would have to trust what it was handed.
	 *
	 * @var int
	 */
	public readonly int $targetTokens;

	/**
	 * Construct.
	 *
	 * @param int      $maxTokens    Ceiling a chunk may never exceed.
	 * @param float    $overlap      Fraction of a chunk repeated in the next.
	 * @param int      $minTokens    Below this, a trailing chunk is merged back.
	 * @param int|null $targetTokens Size to aim for; defaults to the smaller
	 *                               of DEFAULT_TARGET_TOKENS and the ceiling.
	 */
	public function __construct(
		public readonly int $maxTokens = self::DEFAULT_MAX_TOKENS,
		public readonly float $overlap = self::DEFAULT_OVERLAP,
		public readonly int $minTokens = self::DEFAULT_MIN_TOKENS,
		?int $targetTokens = null,
	) {
		// Clamped rather than validated. The ceiling arrives from stored
		// source configuration and can legitimately be below the default
		// target, at which point the target is simply the ceiling — a
		// target above the size a chunk may reach is not a target.
		$this->targetTokens = max(
			1,
			min( $maxTokens, $targetTokens ?? self::DEFAULT_TARGET_TOKENS )
		);
	}

	/**
	 * Build from stored source configuration, clamping to sane bounds.
	 *
	 * The values reach here from a settings form and from JSON written by
	 * an older version of the plugin. Neither is trustworthy: an overlap
	 * of 1.0 would repeat every chunk in full and never advance, and a
	 * max of 0 would loop forever producing empty chunks.
	 *
	 * @param array<string, mixed> $config Source configuration.
	 * @return self
	 */
	public static function fromConfig( array $config ): self {
		$max = isset( $config['chunk_tokens'] ) && is_numeric( $config['chunk_tokens'] )
			? (int) $config['chunk_tokens']
			: self::DEFAULT_MAX_TOKENS;

		$overlap = isset( $config['chunk_overlap'] ) && is_numeric( $config['chunk_overlap'] )
			? (float) $config['chunk_overlap']
			: self::DEFAULT_OVERLAP;

		$max = max( 64, min( self::ABSOLUTE_MAX_TOKENS, $max ) );

		// Capped below 0.5 rather than below 1.0. At half a chunk of
		// overlap every passage is already stored twice, which doubles
		// the embedding bill and the storage for no measured gain.
		$overlap = max( 0.0, min( 0.5, $overlap ) );

		/*
		 * Floored as well as capped. The constructor's own floor is 1,
		 * which is right for code that builds these directly — the packing
		 * tests need small targets — and wrong for a value that arrived
		 * from a request body or from JSON an older version wrote.
		 */
		$target = isset( $config['chunk_target'] ) && is_numeric( $config['chunk_target'] )
			? max( self::MIN_TARGET_TOKENS, min( $max, (int) $config['chunk_target'] ) )
			: null;

		return new self(
			$max,
			$overlap,
			min( self::DEFAULT_MIN_TOKENS, (int) floor( $max / 4 ) ),
			$target
		);
	}

	/**
	 * Overlap expressed in tokens.
	 *
	 * @return int
	 */
	public function overlapTokens(): int {
		// Against the target rather than the ceiling. Overlap is a fraction
		// of a chunk, and chunks are now target-sized — 15% of 800 is 120
		// tokens carried into a 200-token chunk, which is more than half of
		// it and would stop the packer advancing.
		return (int) floor( $this->targetTokens * $this->overlap );
	}
}
