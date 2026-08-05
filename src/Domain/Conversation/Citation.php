<?php
/**
 * Citation entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Conversation;

/**
 * A source the clerk leaned on for one reply.
 *
 * The snapshot is the point of this class. `chunkId` and `documentId` are
 * kept so a live citation can link to the current text, but they are
 * nullable and they are not what gets displayed: a re-index replaces every
 * chunk id on the site, and a deleted source takes its documents with it.
 * A transcript from March has to keep meaning something in June, so the
 * title, URL, heading path and excerpt are copied at the moment of use and
 * read back from the copy.
 */
final class Citation {

	/**
	 * Construct.
	 *
	 * @param int|null    $id          Storage id.
	 * @param int|null    $messageId   Message this supports.
	 * @param int|null    $chunkId     Chunk cited, when it still exists.
	 * @param int|null    $documentId  Document cited, when it still exists.
	 * @param float       $score       Cosine similarity at time of use.
	 * @param int         $rank        Position in the citation list, from 1.
	 * @param string      $title       Document title, snapshotted.
	 * @param string|null $url         Document URL, snapshotted.
	 * @param string|null $headingPath Heading trail within the document.
	 * @param string      $excerpt     Quoted text, snapshotted.
	 */
	public function __construct(
		public ?int $id,
		public ?int $messageId,
		public ?int $chunkId,
		public ?int $documentId,
		public float $score = 0.0,
		public int $rank = 1,
		public string $title = '',
		public ?string $url = null,
		public ?string $headingPath = null,
		public string $excerpt = '',
	) {
	}

	/**
	 * The snapshot as it is stored and as the widget reads it.
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot(): array {
		return array(
			'title'        => $this->title,
			'url'          => $this->url,
			'heading_path' => $this->headingPath,
			'excerpt'      => $this->excerpt,
		);
	}
}
