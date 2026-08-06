<?php
/**
 * A document as a list renders it.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * Everything the documents screen shows, and nothing it does not.
 *
 * A `Document` carries the whole extracted body in a LONGTEXT column, and
 * the list that shows twenty of them per page renders a title, a URL and
 * three counts. Selecting the entity for that meant reading every body on
 * the page out of the database and across the wire to throw all of it
 * away — on a source of long pages, megabytes per keystroke of the
 * paginator.
 *
 * This exists rather than a `Document` with its `content` left empty,
 * which was the cheaper change and a trap. Nothing reads that field on
 * this path today, but `DocumentRepository::save()` writes it: one future
 * caller that loaded a row for a list, changed a title and saved it back
 * would blank the body of every document it touched, and the ingestion
 * pass that noticed would report it as content that had changed rather
 * than content that had been destroyed. A separate type cannot be handed
 * to `save()` at all.
 */
final class DocumentSummary {

	/**
	 * Construct.
	 *
	 * @param int                  $id         Storage id.
	 * @param string               $title      Document title.
	 * @param string               $url        Where it came from.
	 * @param int                  $tokenCount Estimated tokens.
	 * @param int                  $chunkCount Chunks produced from it.
	 * @param string               $status     Indexing status.
	 * @param array<string, mixed> $metadata   Extractor metadata.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly string $url,
		public readonly int $tokenCount,
		public readonly int $chunkCount,
		public readonly string $status,
		public readonly array $metadata = array()
	) {
	}
}
