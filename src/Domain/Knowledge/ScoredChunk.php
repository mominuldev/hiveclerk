<?php
/**
 * A chunk id with a similarity score.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * What the vector store returns: an id and how well it matched.
 *
 * Text is deliberately absent. The store's job ends at ranking; loading
 * the content for the handful of survivors is the retrieval service's,
 * and keeping them apart is what stops a 200-candidate coarse pass from
 * dragging 200 chunks of text through memory to discard 195 of them.
 */
final class ScoredChunk {

	/**
	 * Construct.
	 *
	 * @param int   $chunkId  Chunk.
	 * @param float $score    Cosine similarity, -1 to 1.
	 * @param int   $sourceId Owning source.
	 */
	public function __construct(
		public readonly int $chunkId,
		public readonly float $score,
		public readonly int $sourceId = 0
	) {
	}
}
