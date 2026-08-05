<?php
/**
 * An internal note on a conversation.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Conversation;

/**
 * Something staff wrote about a conversation, never shown to the visitor.
 *
 * The author is stored as both an id and the name that was current when
 * the note was written. Resolving the name at read time looks tidier and
 * loses the record entirely when the account is deleted — which is
 * exactly when someone is most likely to be reading old notes.
 */
final class ConversationNote {

	/**
	 * Longest note kept.
	 *
	 * Notes live in a JSON column alongside the conversation, so an
	 * unbounded one would be read on every list query that hydrates the
	 * row. Two thousand characters is a paragraph of context, which is
	 * what a note is for.
	 */
	public const MAX_LENGTH = 2000;

	/**
	 * Construct.
	 *
	 * @param string   $text       What was written.
	 * @param int|null $authorId   WordPress user id, null when the author is gone.
	 * @param string   $authorName Display name captured at write time.
	 * @param string   $createdAt  UTC timestamp, Y-m-d H:i:s.
	 */
	public function __construct(
		public readonly string $text,
		public readonly ?int $authorId,
		public readonly string $authorName,
		public readonly string $createdAt,
	) {
	}

	/**
	 * Rebuild from the stored JSON, or null when the entry is unusable.
	 *
	 * @param mixed $stored One decoded entry.
	 * @return self|null
	 */
	public static function fromArray( mixed $stored ): ?self {
		if ( ! is_array( $stored ) ) {
			return null;
		}

		$text = $stored['text'] ?? null;

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return null;
		}

		$authorId = $stored['author_id'] ?? null;

		return new self(
			text: mb_substr( trim( $text ), 0, self::MAX_LENGTH ),
			authorId: is_numeric( $authorId ) ? (int) $authorId : null,
			authorName: is_string( $stored['author_name'] ?? null ) ? (string) $stored['author_name'] : 'Someone',
			createdAt: is_string( $stored['created_at'] ?? null ) ? (string) $stored['created_at'] : '',
		);
	}

	/**
	 * The shape stored in the JSON column.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'text'        => $this->text,
			'author_id'   => $this->authorId,
			'author_name' => $this->authorName,
			'created_at'  => $this->createdAt,
		);
	}
}
