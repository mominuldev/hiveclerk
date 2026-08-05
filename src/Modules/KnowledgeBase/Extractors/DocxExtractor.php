<?php
/**
 * DOCX extraction.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Hiveclerk\Domain\Knowledge\KnowledgeSource;
use Hiveclerk\Domain\Knowledge\SourceType;
use Hiveclerk\Modules\KnowledgeBase\Text\NormalisedText;
use Hiveclerk\Modules\KnowledgeBase\Text\TextBlock;
use RuntimeException;
use ZipArchive;

/**
 * Indexes uploaded Word documents (FR-KB-03).
 *
 * A .docx is a zip of XML, so no third-party library is needed — the two
 * extensions this uses ship with essentially every PHP build, and one of
 * them is already required for the HTML normaliser.
 *
 * ## Word's own heading styles become the heading path
 *
 * This is what makes DOCX a genuinely good source rather than a
 * grudgingly supported one. A policy document written in Word has real
 * structure — Heading 1, Heading 2 — recorded in the markup, and that
 * maps directly onto the chunker's heading path. The result is better
 * chunking than the same text pasted into a textarea.
 *
 * Only .docx. The older binary .doc is a different, undocumented format
 * that cannot be read this way; it is reported as such rather than
 * producing an empty document.
 */
final class DocxExtractor extends AbstractExtractor {

	/**
	 * Where the body lives inside the archive.
	 */
	private const BODY = 'word/document.xml';

	/**
	 * WordprocessingML namespace.
	 */
	private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	public function type(): SourceType {
		return SourceType::Docx;
	}

	public function isAvailable(): bool {
		return class_exists( ZipArchive::class );
	}

	public function unavailableReason(): string {
		return $this->isAvailable()
			? ''
			: 'PHP is built without the zip extension, which is needed to read Word documents.';
	}

	public function estimate( KnowledgeSource $source ): int {
		return count( $this->files( $source ) );
	}

	/**
	 * Yield one document per attached file.
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

			$blocks = $this->blocks( $path );

			if ( array() === $blocks ) {
				continue;
			}

			$name  = basename( $path );
			$title = trim( str_replace( array( '-', '_' ), ' ', pathinfo( $name, PATHINFO_FILENAME ) ) );

			yield new ExtractedDocument(
				externalId: 'docx-' . $attachmentId,
				title: $title,
				text: NormalisedText::fromBlocks( $blocks ),
				url: (string) wp_get_attachment_url( $attachmentId ),
				metadata: array(
					'kind'          => 'docx',
					'attachment_id' => $attachmentId,
					'file'          => $name,
				),
			);
		}
	}

	/**
	 * Read a .docx into blocks.
	 *
	 * @param string $path File path.
	 * @return array<int, TextBlock>
	 */
	private function blocks( string $path ): array {
		$xml = $this->body( $path );

		$document = new DOMDocument();

		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadXML( $xml, LIBXML_NONET | LIBXML_NOENT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			throw new RuntimeException( sprintf( '%s is not a readable Word document.', basename( $path ) ) );
		}

		$xpath = new DOMXPath( $document );
		$xpath->registerNamespace( 'w', self::NS );

		$paragraphs = $xpath->query( '//w:body//w:p' );

		if ( false === $paragraphs ) {
			return array();
		}

		$blocks = array();
		$heads  = array();

		foreach ( $paragraphs as $paragraph ) {
			if ( ! $paragraph instanceof DOMElement ) {
				continue;
			}

			$text = $this->paragraphText( $xpath, $paragraph );

			if ( '' === $text ) {
				continue;
			}

			$level = $this->headingLevel( $xpath, $paragraph );

			if ( null !== $level ) {
				// Same rule as the HTML normaliser: the heading becomes
				// the path rather than a block, so it is not embedded
				// twice once the chunk is contextualised.
				$depth   = min( $level - 1, count( $heads ) );
				$heads   = array_slice( $heads, 0, max( 0, $depth ) );
				$heads[] = $text;

				continue;
			}

			$blocks[] = new TextBlock( $text, $heads );
		}

		return $blocks;
	}

	/**
	 * Pull document.xml out of the archive.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private function body( string $path ): string {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $path ) ) {
			throw new RuntimeException(
				sprintf(
					'%s could not be opened. If it is an older .doc file, resave it as .docx first.',
					basename( $path )
				)
			);
		}

		$xml = $zip->getFromName( self::BODY );

		$zip->close();

		if ( false === $xml || '' === $xml ) {
			throw new RuntimeException(
				sprintf( '%s does not contain a Word document body.', basename( $path ) )
			);
		}

		return $xml;
	}

	/**
	 * The text of one paragraph.
	 *
	 * Word splits a sentence across as many runs as it has formatting
	 * changes, so "the price is £30" can be four elements. Concatenating
	 * the runs is what puts the sentence back together.
	 *
	 * @param DOMXPath   $xpath     Query engine.
	 * @param DOMElement $paragraph Paragraph.
	 * @return string
	 */
	private function paragraphText( DOMXPath $xpath, DOMElement $paragraph ): string {
		$runs = $xpath->query( './/w:t | .//w:tab | .//w:br', $paragraph );

		if ( false === $runs ) {
			return '';
		}

		$text = '';

		foreach ( $runs as $run ) {
			if ( ! $run instanceof DOMElement ) {
				continue;
			}

			$text .= match ( $run->localName ) {
				'tab'   => ' ',
				'br'    => "\n",
				default => $run->textContent,
			};
		}

		return trim( preg_replace( '/[ \t]{2,}/u', ' ', $text ) ?? $text );
	}

	/**
	 * The heading level of a paragraph, if it is one.
	 *
	 * @param DOMXPath   $xpath     Query engine.
	 * @param DOMElement $paragraph Paragraph.
	 * @return int|null
	 */
	private function headingLevel( DOMXPath $xpath, DOMElement $paragraph ): ?int {
		$style = $xpath->query( './w:pPr/w:pStyle/@w:val', $paragraph );

		if ( false === $style || 0 === $style->length ) {
			return null;
		}

		$name = (string) $style->item( 0 )?->nodeValue;

		// Word writes "Heading1" in English builds and localises the
		// display name only, so the style id stays matchable. "berschrift1"
		// appears in some German-authored files where the id was localised
		// too, hence the looser trailing-digit match.
		if ( 1 === preg_match( '/^(?:Heading|berschrift|Ttulo|Titre)(\d)$/i', $name, $matches ) ) {
			return max( 1, min( 6, (int) $matches[1] ) );
		}

		return null;
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
