<?php
/**
 * An assembled document and where each block landed in it.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Text;

/**
 * The plain text of a document, plus the span each block occupies.
 *
 * Assembly happens here and nowhere else, which is what makes one
 * invariant hold across every extractor: **a span always addresses the
 * text exactly**. Chunks are produced by slicing spans, so a chunk is
 * always a literal substring of the document — never a re-concatenation
 * of pieces that might differ from it by a space.
 *
 * That sounds pedantic until a citation has to highlight the passage it
 * came from. Offsets that are approximately right highlight the wrong
 * sentence, and nothing in the system notices.
 */
final class NormalisedText {

	/**
	 * What separates two blocks in the assembled text.
	 */
	public const BLOCK_SEPARATOR = "\n\n";

	/**
	 * Construct.
	 *
	 * @param string                $text  Full plain text.
	 * @param array<int, TextSpan>  $spans Block spans, in document order.
	 */
	private function __construct(
		public readonly string $text,
		public readonly array $spans,
	) {
	}

	/**
	 * Assemble blocks into a document.
	 *
	 * @param array<int, TextBlock> $blocks Blocks in document order.
	 * @return self
	 */
	public static function fromBlocks( array $blocks ): self {
		$text   = '';
		$spans  = array();
		$cursor = 0;

		foreach ( $blocks as $block ) {
			if ( $block->isEmpty() ) {
				continue;
			}

			$body = trim( $block->text );

			if ( '' !== $text ) {
				$text   .= self::BLOCK_SEPARATOR;
				$cursor += strlen( self::BLOCK_SEPARATOR );
			}

			$text .= $body;

			$spans[] = new TextSpan(
				$cursor,
				$cursor + strlen( $body ),
				$block->headingPath
			);

			$cursor += strlen( $body );
		}

		return new self( $text, $spans );
	}

	/**
	 * Build from text that has no structure to preserve.
	 *
	 * Raw text sources and extracted PDF pages arrive as prose with blank
	 * lines and nothing else. Splitting on those recovers the paragraph
	 * boundaries the chunker needs without inventing headings that are
	 * not there.
	 *
	 * @param string             $text        Plain text.
	 * @param array<int, string> $headingPath Path applied to every block.
	 * @return self
	 */
	public static function fromPlainText( string $text, array $headingPath = array() ): self {
		$normalised = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$paragraphs = preg_split( '/\n{2,}/', $normalised );

		if ( false === $paragraphs ) {
			$paragraphs = array( $normalised );
		}

		$blocks = array();

		foreach ( $paragraphs as $paragraph ) {
			$blocks[] = new TextBlock( $paragraph, $headingPath );
		}

		return self::fromBlocks( $blocks );
	}

	/**
	 * Whether the document has any content.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return '' === trim( $this->text );
	}

	/**
	 * Slice the document.
	 *
	 * @param int $start Byte offset.
	 * @param int $end   Byte offset, exclusive.
	 * @return string
	 */
	public function slice( int $start, int $end ): string {
		return substr( $this->text, $start, max( 0, $end - $start ) );
	}

	/**
	 * Length in bytes.
	 *
	 * @return int
	 */
	public function length(): int {
		return strlen( $this->text );
	}
}
