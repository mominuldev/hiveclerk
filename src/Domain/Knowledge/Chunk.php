<?php
/**
 * Chunk entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * A retrievable span of a document.
 *
 * Chunks are what get embedded and what come back from a search, so the
 * boundaries matter more than they look. A chunk that ends mid-argument
 * retrieves as a fragment the model cannot use; a chunk spanning two
 * unrelated sections dilutes its own vector until it matches nothing
 * well. Both failures show up as "the bot did not know that", which is
 * the hardest kind of bug to trace back to its cause.
 *
 * The heading path is carried for the same reason. "Free returns within
 * 30 days" means one thing under *Shipping* and another under
 * *Wholesale terms*, and the sentence alone does not say which.
 */
final class Chunk {

	/**
	 * Separator between heading levels.
	 *
	 * Chosen because it is unambiguous in a prompt and unlikely to occur
	 * inside a heading. Headings containing it are stripped of it when
	 * the path is built, so the split is always reversible.
	 */
	public const PATH_SEPARATOR = ' > ';

	/**
	 * Storage width of the heading path column.
	 */
	public const PATH_MAX_LENGTH = 500;

	/**
	 * Construct.
	 *
	 * @param int|null           $id          Storage id.
	 * @param int                $documentId  Owning document.
	 * @param int                $sourceId    Owning source, denormalised.
	 * @param int                $chunkIndex  Position within the document.
	 * @param string             $content     The text itself.
	 * @param string             $contentHash sha256 of the content.
	 * @param array<int, string> $headingPath Headings above this chunk.
	 * @param int                $tokenCount  Estimated tokens.
	 * @param int                $charStart   Offset into the document.
	 * @param int                $charEnd     End offset, exclusive.
	 */
	public function __construct(
		public ?int $id,
		public int $documentId,
		public int $sourceId,
		public int $chunkIndex,
		public string $content,
		public string $contentHash = '',
		public array $headingPath = array(),
		public int $tokenCount = 0,
		public int $charStart = 0,
		public int $charEnd = 0,
	) {
		if ( '' === $this->contentHash ) {
			$this->contentHash = hash( 'sha256', $this->content );
		}
	}

	/**
	 * The heading path as stored.
	 *
	 * Truncated from the left when it is too long for the column. Losing
	 * the outermost heading costs less context than losing the innermost
	 * one, which is the heading nearest the text and the most specific.
	 *
	 * @return string
	 */
	public function path(): string {
		$clean = array();

		foreach ( $this->headingPath as $heading ) {
			$stripped = trim( str_replace( self::PATH_SEPARATOR, ' ', $heading ) );

			if ( '' !== $stripped ) {
				$clean[] = $stripped;
			}
		}

		$path = implode( self::PATH_SEPARATOR, $clean );

		while ( strlen( $path ) > self::PATH_MAX_LENGTH && count( $clean ) > 1 ) {
			array_shift( $clean );
			$path = implode( self::PATH_SEPARATOR, $clean );
		}

		return substr( $path, 0, self::PATH_MAX_LENGTH );
	}

	/**
	 * The text as it should be handed to a model.
	 *
	 * The heading path is prefixed rather than stored inside the content,
	 * so the stored text stays byte-identical to the document and the
	 * character offsets keep pointing at real positions.
	 *
	 * @return string
	 */
	public function contextualised(): string {
		$path = $this->path();

		return '' === $path ? $this->content : $path . "\n\n" . $this->content;
	}

	/**
	 * Split a stored heading path back into levels.
	 *
	 * @param string|null $path Stored value.
	 * @return array<int, string>
	 */
	public static function splitPath( ?string $path ): array {
		if ( null === $path || '' === trim( $path ) ) {
			return array();
		}

		return array_values( array_filter( explode( self::PATH_SEPARATOR, $path ) ) );
	}
}
