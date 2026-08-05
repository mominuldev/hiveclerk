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
	 * Construct.
	 *
	 * @param int   $maxTokens Target chunk size.
	 * @param float $overlap   Fraction of a chunk repeated in the next.
	 * @param int   $minTokens Below this, a trailing chunk is merged back.
	 */
	public function __construct(
		public readonly int $maxTokens = self::DEFAULT_MAX_TOKENS,
		public readonly float $overlap = self::DEFAULT_OVERLAP,
		public readonly int $minTokens = self::DEFAULT_MIN_TOKENS,
	) {
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

		return new self( $max, $overlap, min( self::DEFAULT_MIN_TOKENS, (int) floor( $max / 4 ) ) );
	}

	/**
	 * Overlap expressed in tokens.
	 *
	 * @return int
	 */
	public function overlapTokens(): int {
		return (int) floor( $this->maxTokens * $this->overlap );
	}
}
