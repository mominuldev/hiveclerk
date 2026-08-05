<?php
/**
 * A paragraph-sized piece of extracted text.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Text;

/**
 * One block of text and the headings it sits under.
 *
 * Extractors produce these; NormalisedText assembles them into a document
 * and works out where each one landed. Splitting the two means an
 * extractor never has to think about character offsets, and offsets are
 * computed in exactly one place rather than in seven extractors.
 */
final class TextBlock {

	/**
	 * Construct.
	 *
	 * @param string             $text        Block text, already plain.
	 * @param array<int, string> $headingPath Headings above this block,
	 *                                        outermost first.
	 */
	public function __construct(
		public readonly string $text,
		public readonly array $headingPath = array(),
	) {
	}

	/**
	 * Whether this block carries anything.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return '' === trim( $this->text );
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
