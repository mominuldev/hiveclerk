<?php
/**
 * A range of a document.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Text;

/**
 * Half-open byte range [start, end) carrying its heading path.
 *
 * Offsets are byte offsets, not character offsets, because that is what
 * substr() takes and what the database stores. Mixing the two is the
 * classic way to cut a multi-byte character in half — which produces
 * invalid UTF-8 that MySQL rejects on insert, so at least it fails
 * loudly.
 */
final class TextSpan {

	/**
	 * Construct.
	 *
	 * @param int                $start       Byte offset.
	 * @param int                $end         Byte offset, exclusive.
	 * @param array<int, string> $headingPath Headings above this span.
	 */
	public function __construct(
		public readonly int $start,
		public readonly int $end,
		public readonly array $headingPath = array(),
	) {
	}

	/**
	 * Length in bytes.
	 *
	 * @return int
	 */
	public function length(): int {
		return max( 0, $this->end - $this->start );
	}

	/**
	 * The heading path as a comparable key.
	 *
	 * @return string
	 */
	public function pathKey(): string {
		return implode( "\x1f", $this->headingPath );
	}
}
