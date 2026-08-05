<?php
/**
 * Raw text sources.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors;

use Hiveclerk\Domain\Knowledge\KnowledgeSource;
use Hiveclerk\Domain\Knowledge\SourceType;
use Hiveclerk\Modules\KnowledgeBase\Text\NormalisedText;

/**
 * Indexes text typed straight into the admin (FR-KB-05).
 *
 * The escape hatch. Everything the clerk needs to know that is not
 * written down anywhere on the site — opening hours during a holiday,
 * the answer to the question the sales team is tired of — goes here.
 * In practice it is the first source most customers create and the one
 * they edit most often.
 */
final class TextExtractor extends AbstractExtractor {

	public function type(): SourceType {
		return SourceType::Text;
	}

	public function estimate( KnowledgeSource $source ): int {
		return '' === $this->stringConfig( $source, 'content' ) ? 0 : 1;
	}

	/**
	 * Yield the text as a single document.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return iterable<int, ExtractedDocument>
	 */
	public function extract( KnowledgeSource $source ): iterable {
		$content = $this->stringConfig( $source, 'content' );

		if ( '' === $content ) {
			return;
		}

		$language = $this->stringConfig( $source, 'language' );

		yield new ExtractedDocument(
			// A stable id, not one derived from the content. Deriving it
			// from the text would make every edit look like a new document
			// and leave the previous version indexed alongside it.
			externalId: 'text-' . (int) $source->id,
			title: $source->name,
			text: NormalisedText::fromPlainText( $content ),
			url: '',
			metadata: array( 'kind' => 'text' ),
			language: '' === $language ? null : $language,
		);
	}
}
