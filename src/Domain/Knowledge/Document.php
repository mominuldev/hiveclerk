<?php
/**
 * Document entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * One retrievable item inside a knowledge source.
 *
 * A post, a product, a crawled page, one PDF. The unit a citation points
 * at, which is why the URL and title live here rather than being derived
 * later: by the time a chunk is retrieved, the extractor that knew where
 * it came from ran days ago.
 */
final class Document {

	/**
	 * Construct.
	 *
	 * @param int|null             $id          Storage id.
	 * @param int                  $sourceId    Owning source.
	 * @param string               $externalId  Identifier within the source.
	 * @param string               $url         Canonical location.
	 * @param string               $title       Display title.
	 * @param string               $content     Normalised plain text.
	 * @param string               $contentHash sha256 of the content.
	 * @param string|null          $language    BCP-47 tag, when known.
	 * @param array<string, mixed> $metadata    Type-specific extras.
	 * @param int                  $tokenCount  Estimated tokens.
	 * @param int                  $chunkCount  Chunks produced.
	 * @param string               $status      pending|indexed|error.
	 */
	public function __construct(
		public ?int $id,
		public int $sourceId,
		public string $externalId,
		public string $url = '',
		public string $title = '',
		public string $content = '',
		public string $contentHash = '',
		public ?string $language = null,
		public array $metadata = array(),
		public int $tokenCount = 0,
		public int $chunkCount = 0,
		public string $status = 'pending',
	) {
	}

	/**
	 * Compute the content hash for a body of text.
	 *
	 * Change detection turns on this. Re-indexing a source that has not
	 * changed should cost nothing, and re-embedding an unchanged document
	 * costs real money at the provider — so "did this change" has to be
	 * answerable without asking a model.
	 *
	 * @param string $content Normalised text.
	 * @return string
	 */
	public static function hash( string $content ): string {
		return hash( 'sha256', $content );
	}

	/**
	 * Whether stored content differs from what was just extracted.
	 *
	 * @param string $content Freshly extracted, normalised text.
	 * @return bool
	 */
	public function hasChanged( string $content ): bool {
		return self::hash( $content ) !== $this->contentHash;
	}

	/**
	 * Whether there is anything worth indexing.
	 *
	 * An empty document is not an error — a page can legitimately be a
	 * gallery with no prose — but it must not be counted as indexed, or
	 * the source reports coverage it does not have.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return '' === trim( $this->content );
	}
}
