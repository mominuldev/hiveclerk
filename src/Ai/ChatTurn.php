<?php
/**
 * One turn of a conversation, in provider terms.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

use Hiveclerk\Domain\Conversation\MessageRole;

/**
 * A single message as a provider expects to receive it.
 *
 * Separate from the domain's Message because the two answer different
 * questions. A domain Message is a stored record with an id, citations and
 * a cost; a turn is the transport shape. Keeping them apart means the
 * prompt assembled in Sprint 5 can drop, merge or rewrite turns without
 * touching what was persisted.
 */
final class ChatTurn {

	private const USER      = 'user';
	private const ASSISTANT = 'assistant';

	/**
	 * Construct.
	 *
	 * @param string $role    Either "user" or "assistant".
	 * @param string $content Message text.
	 */
	private function __construct(
		public readonly string $role,
		public readonly string $content
	) {
	}

	/**
	 * A turn written by the visitor.
	 *
	 * @param string $content Text.
	 * @return self
	 */
	public static function user( string $content ): self {
		return new self( self::USER, $content );
	}

	/**
	 * A turn written by the clerk.
	 *
	 * @param string $content Text.
	 * @return self
	 */
	public static function assistant( string $content ): self {
		return new self( self::ASSISTANT, $content );
	}

	/**
	 * Convert a stored message role.
	 *
	 * A human agent's reply is sent as an assistant turn: from the model's
	 * point of view it is prior output from its own side of the
	 * conversation, and labelling it otherwise would make the model
	 * respond to a colleague as though a visitor had spoken.
	 *
	 * @param MessageRole $role    Stored role.
	 * @param string      $content Text.
	 * @return self
	 */
	public static function fromRole( MessageRole $role, string $content ): self {
		return match ( $role ) {
			MessageRole::Visitor => self::user( $content ),
			default              => self::assistant( $content ),
		};
	}

	/**
	 * Whether this turn came from the visitor.
	 *
	 * @return bool
	 */
	public function isUser(): bool {
		return self::USER === $this->role;
	}
}
