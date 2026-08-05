<?php
/**
 * Widget session entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Conversation;

use DateTimeImmutable;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * One visitor's authenticated window onto one conversation.
 *
 * A session is the whole of the widget's authorisation story. There is no
 * cookie, no login and no user: the visitor holds a signed token and the
 * token names exactly one conversation. That is what makes SEC-11 —
 * reading somebody else's transcript by guessing at an identifier —
 * structurally impossible rather than merely checked: the conversation is
 * not a parameter the caller supplies, it is a property of the credential
 * they present.
 *
 * The token itself is never stored. Only its SHA-256 hash is, for the same
 * reason a password is not stored: a database dump would otherwise hand
 * the reader a working credential for every live conversation on the site.
 */
final class Session {

	/**
	 * Construct.
	 *
	 * @param int|null               $id             Storage id, null before first save.
	 * @param Uuid                   $uuid           Public identifier.
	 * @param string                 $tokenHash      SHA-256 of the issued token.
	 * @param int|null               $conversationId Conversation this token opens.
	 * @param int|null               $visitorId      Visitor, once identified.
	 * @param string                 $transport      'sse' or 'poll'.
	 * @param string|null            $ipHash         Salted hash of the caller's IP.
	 * @param DateTimeImmutable|null $expiresAt      Expiry, UTC.
	 * @param DateTimeImmutable|null $createdAt      Issue time, UTC.
	 */
	public function __construct(
		public ?int $id,
		public Uuid $uuid,
		public string $tokenHash,
		public ?int $conversationId = null,
		public ?int $visitorId = null,
		public string $transport = 'sse',
		public ?string $ipHash = null,
		public ?DateTimeImmutable $expiresAt = null,
		public ?DateTimeImmutable $createdAt = null,
	) {
	}

	/**
	 * Whether this session has passed its expiry.
	 *
	 * An expired session is rejected, never silently renewed. Renewal would
	 * make the expiry decorative: a token leaked once would stay valid for
	 * as long as anybody kept using it.
	 *
	 * @param DateTimeImmutable $now Current time.
	 * @return bool
	 */
	public function hasExpired( DateTimeImmutable $now ): bool {
		if ( null === $this->expiresAt ) {
			return true;
		}

		return $this->expiresAt <= $now;
	}

	/**
	 * Whether this session may act on a given conversation.
	 *
	 * @param int $conversationId Conversation being addressed.
	 * @return bool
	 */
	public function owns( int $conversationId ): bool {
		return null !== $this->conversationId && $this->conversationId === $conversationId;
	}
}
