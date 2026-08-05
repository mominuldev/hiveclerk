<?php
/**
 * One document as an extractor found it.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors;

use Hiveclerk\Modules\KnowledgeBase\Text\NormalisedText;

/**
 * What an extractor yields.
 *
 * Deliberately not a `Document`. A Document is a stored row with an id
 * and counts that only ingestion can fill in; this is the raw find, and
 * keeping them apart stops an extractor being handed a repository.
 *
 * The text arrives already normalised, because only the extractor knows
 * whether it is holding HTML, a PDF page or a spreadsheet cell.
 */
final class ExtractedDocument {

	/**
	 * Construct.
	 *
	 * @param string               $externalId Identifier within the source.
	 *                                         Must be stable across syncs:
	 *                                         it is what makes a re-index an
	 *                                         update instead of a duplicate.
	 * @param string               $title      Display title.
	 * @param NormalisedText       $text       Normalised content.
	 * @param string               $url        Canonical location, for citations.
	 * @param array<string, mixed> $metadata   Type-specific extras.
	 * @param string|null          $language   BCP-47 tag, when known.
	 */
	public function __construct(
		public readonly string $externalId,
		public readonly string $title,
		public readonly NormalisedText $text,
		public readonly string $url = '',
		public readonly array $metadata = array(),
		public readonly ?string $language = null,
	) {
	}

	/**
	 * Whether there is anything worth storing.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return $this->text->isEmpty();
	}
}
