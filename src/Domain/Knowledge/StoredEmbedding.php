<?php
/**
 * Embedding as it sits on disk.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * One row of the embeddings table, before or after it is written.
 *
 * Deliberately holds the encoded bytes rather than the float array. The
 * repository's job is to move bytes; deciding how a vector becomes bytes
 * is the vector store's, and keeping the two apart is what lets the SaaS
 * binding swap the store without the persistence layer knowing.
 */
final class StoredEmbedding {

	/**
	 * Construct.
	 *
	 * @param int    $chunkId    Owning chunk.
	 * @param int    $sourceId   Owning source, denormalised for the scan.
	 * @param string $provider   Provider that produced it.
	 * @param string $model      Model that produced it.
	 * @param int    $dimensions Vector width.
	 * @param string $f32        Packed float32, little-endian.
	 * @param string $bits       One bit per dimension, packed big-endian per byte.
	 * @param float  $norm       Precomputed L2 norm.
	 */
	public function __construct(
		public readonly int $chunkId,
		public readonly int $sourceId,
		public readonly string $provider,
		public readonly string $model,
		public readonly int $dimensions,
		public readonly string $f32,
		public readonly string $bits,
		public readonly float $norm
	) {
	}
}
