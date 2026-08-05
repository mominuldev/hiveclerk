<?php
/**
 * PDF extraction.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors;

use Hiveclerk\Domain\Knowledge\KnowledgeSource;
use Hiveclerk\Domain\Knowledge\SourceType;
use Hiveclerk\Modules\KnowledgeBase\Text\NormalisedText;
use Hiveclerk\Modules\KnowledgeBase\Text\TextBlock;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Indexes uploaded PDFs (FR-KB-03).
 *
 * ## One document per page
 *
 * A 300-page manual as a single document would produce a content column
 * of several megabytes and character offsets running into the millions,
 * and a citation could only ever say "somewhere in the manual". Per page
 * gives the citation a page number, which is what a reader needs to
 * check the answer.
 *
 * ## What this cannot do
 *
 * A scanned PDF is a sequence of images. There is no text to extract and
 * no amount of parsing produces any, so such a file is reported as
 * yielding nothing rather than being silently indexed as empty. OCR is
 * out of scope for V1 and would need a paid service or a binary that
 * shared hosts do not have.
 */
final class PdfExtractor extends AbstractExtractor {

	/**
	 * Largest file worth attempting, in bytes.
	 *
	 * Parsing loads the document structure into memory. A file beyond
	 * this exhausts a shared host's limit and takes the whole import with
	 * it, so it is refused with an explanation instead.
	 */
	private const MAX_BYTES = 33554432;

	public function type(): SourceType {
		return SourceType::Pdf;
	}

	public function isAvailable(): bool {
		return class_exists( Parser::class );
	}

	public function unavailableReason(): string {
		return $this->isAvailable()
			? ''
			: 'The PDF parser is missing from this installation.';
	}

	public function estimate( KnowledgeSource $source ): int {
		// Page counts are only knowable by parsing the file, which is the
		// expensive part. Reporting the number of files gives the progress
		// bar something honest to count instead.
		return count( $this->files( $source ) );
	}

	/**
	 * Yield one document per page of each attached PDF.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return iterable<int, ExtractedDocument>
	 */
	public function extract( KnowledgeSource $source ): iterable {
		foreach ( $this->files( $source ) as $attachmentId ) {
			$path = get_attached_file( $attachmentId );

			if ( ! is_string( $path ) || ! is_readable( $path ) ) {
				continue;
			}

			$size = filesize( $path );

			if ( false !== $size && $size > self::MAX_BYTES ) {
				throw new RuntimeException(
					sprintf(
						'%s is %d MB, larger than the %d MB limit for a single document.',
						basename( $path ),
						(int) round( $size / 1048576 ),
						(int) round( self::MAX_BYTES / 1048576 )
					)
				);
			}

			yield from $this->pages( $attachmentId, $path );
		}
	}

	/**
	 * Read one file's pages.
	 *
	 * @param int    $attachmentId Attachment.
	 * @param string $path         File path.
	 * @return iterable<int, ExtractedDocument>
	 */
	private function pages( int $attachmentId, string $path ): iterable {
		$name = basename( $path );

		try {
			$pdf = ( new Parser() )->parseFile( $path );
		} catch ( Throwable $e ) {
			// Encrypted, malformed, or built by something that disagrees
			// with the specification. One unreadable file must not fail
			// the other thirty-nine, so this becomes a per-document
			// failure the ingestion service counts.
			throw new RuntimeException(
				sprintf( '%s could not be read: %s', $name, $e->getMessage() ),
				0,
				$e
			);
		}

		$title = $this->title( $pdf->getDetails(), $name );
		$pages = $pdf->getPages();
		$empty = 0;

		foreach ( $pages as $number => $page ) {
			$text = trim( $page->getText() );

			if ( '' === $text ) {
				++$empty;

				continue;
			}

			$label = sprintf( 'Page %d', $number + 1 );

			yield new ExtractedDocument(
				externalId: sprintf( 'pdf-%d-p%d', $attachmentId, $number + 1 ),
				title: sprintf( '%s — %s', $title, $label ),
				text: NormalisedText::fromBlocks(
					array( new TextBlock( $this->tidy( $text ), array( $title, $label ) ) )
				),
				url: (string) wp_get_attachment_url( $attachmentId ),
				metadata: array(
					'kind'          => 'pdf',
					'attachment_id' => $attachmentId,
					'file'          => $name,
					'page'          => $number + 1,
					'pages'         => count( $pages ),
				),
			);
		}

		if ( count( $pages ) > 0 && count( $pages ) === $empty ) {
			// Every page parsed and none had a character in it. That is a
			// scan, and saying so is the difference between a customer
			// running it through OCR and a customer filing a bug.
			throw new RuntimeException(
				sprintf(
					'%s contains no selectable text. It looks like a scanned document, which needs to be run through OCR before it can be indexed.',
					$name
				)
			);
		}
	}

	/**
	 * A readable title for the document.
	 *
	 * @param array<string, mixed> $details PDF metadata.
	 * @param string               $name    File name.
	 * @return string
	 */
	private function title( array $details, string $name ): string {
		$title = $details['Title'] ?? null;

		if ( is_string( $title ) && '' !== trim( $title ) ) {
			return trim( $title );
		}

		// Falling back to the file name, tidied: "product-manual-v2.pdf"
		// reads better as "product manual v2" in a citation.
		return trim( str_replace( array( '-', '_' ), ' ', pathinfo( $name, PATHINFO_FILENAME ) ) );
	}

	/**
	 * Repair the whitespace PDF extraction produces.
	 *
	 * Text laid out for print carries the line breaks of the page rather
	 * than of the sentence, so a paragraph arrives as eight short lines.
	 * Left alone, the chunker treats each as its own block and every
	 * chunk becomes a fragment.
	 *
	 * @param string $text Extracted text.
	 * @return string
	 */
	private function tidy( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		// A hyphen at a line end is a word broken across lines, not a
		// hyphenated word.
		$text = preg_replace( '/(\w)-\n(\w)/u', '$1$2', $text ) ?? $text;

		// A single newline mid-sentence is layout; a blank line is a real
		// paragraph break and is kept.
		$text = preg_replace( '/(?<![\n.!?:])\n(?!\n)/u', ' ', $text ) ?? $text;

		$text = preg_replace( '/[ \t]{2,}/u', ' ', $text ) ?? $text;

		return trim( $text );
	}

	/**
	 * Attachment ids the source points at.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return array<int, int>
	 */
	private function files( KnowledgeSource $source ): array {
		return array_values(
			array_filter(
				array_map( 'intval', $this->stringList( $source, 'attachments' ) )
			)
		);
	}
}
