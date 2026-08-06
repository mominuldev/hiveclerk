<?php
/**
 * The quantised matrix scanned by stage 1.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * Every quantised vector in a source set, as one contiguous string.
 *
 * The shape is unusual on purpose. Ten thousand small objects, each
 * holding a 192-byte string, costs several megabytes of PHP array and
 * object overhead before a single comparison happens — on a host with a
 * 96 MB request budget that is most of the budget spent on bookkeeping.
 * One string plus one integer list is a few hundred kilobytes of
 * overhead, and it is also the layout that lets the whole matrix be
 * XOR'd against a repeated query in a single operation.
 */
final class EmbeddingMatrix {

	/**
	 * Construct.
	 *
	 * @param array<int, int> $ids   Chunk ids, in row order.
	 * @param string          $bits  Rows concatenated, each exactly $width bytes.
	 * @param int             $width Bytes per row.
	 */
	public function __construct(
		public readonly array $ids,
		public readonly string $bits,
		public readonly int $width
	) {
	}

	/**
	 * An empty matrix.
	 *
	 * @param int $width Bytes per row.
	 * @return self
	 */
	public static function empty( int $width = 0 ): self {
		return new self( array(), '', $width );
	}

	/**
	 * Join per-source matrices into the one stage 1 scans.
	 *
	 * The matrix is cached a source at a time and assembled here, rather
	 * than cached whole. Whole, its size was the size of everything a
	 * clerk is pointed at, which is what put it over Memcached's
	 * one-megabyte item limit at about five thousand chunks and over the
	 * transient ceiling at sixteen thousand — and past either of those a
	 * failed write is indistinguishable from a cache miss, so every
	 * visitor message rebuilt it from a full table scan. Per source, the
	 * unit is bounded by one source, and re-indexing one of forty stops
	 * invalidating the other thirty-nine.
	 *
	 * Rows are fixed-width, so joining is concatenation and the ids follow
	 * in the same order. A shard whose width disagrees with the others is
	 * left out rather than appended: its rows would land at the wrong
	 * offsets and silently corrupt every comparison after them, where
	 * dropping it costs only the vectors in that one source, which the
	 * width check in the coarse pass is already there to explain.
	 *
	 * @param array<int, self> $matrices Shards, in the order to scan them.
	 * @return self
	 */
	public static function concat( array $matrices ): self {
		$ids   = array();
		$bits  = '';
		$width = 0;

		foreach ( $matrices as $matrix ) {
			if ( $matrix->isEmpty() ) {
				continue;
			}

			if ( 0 === $width ) {
				$width = $matrix->width;
			}

			if ( $matrix->width !== $width ) {
				continue;
			}

			foreach ( $matrix->ids as $id ) {
				$ids[] = $id;
			}

			$bits .= $matrix->bits;
		}

		return new self( $ids, $bits, $width );
	}

	/**
	 * How many vectors it holds.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->ids );
	}

	/**
	 * Whether it holds nothing.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return array() === $this->ids;
	}

	/**
	 * Bytes held, for cache accounting and status reporting.
	 *
	 * @return int
	 */
	public function bytes(): int {
		return strlen( $this->bits );
	}

	/**
	 * Whether the row count and the string length agree.
	 *
	 * Checked after a cache read rather than trusted. A truncated value —
	 * a transient that hit `max_allowed_packet`, an object cache that
	 * evicted mid-write — would otherwise be scanned as if it were whole,
	 * silently returning results from the first few thousand chunks only.
	 *
	 * @return bool
	 */
	public function isConsistent(): bool {
		if ( $this->width <= 0 ) {
			return $this->isEmpty();
		}

		return strlen( $this->bits ) === $this->count() * $this->width;
	}
}
