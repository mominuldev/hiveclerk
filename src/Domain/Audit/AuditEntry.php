<?php
/**
 * One audit record.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Audit;

/**
 * Something a person changed.
 *
 * Records who, what and when for every configuration mutation. The point
 * is answering "who turned this on" three months later, which is a
 * question that gets asked on the worst possible day and cannot be
 * answered retroactively.
 *
 * The IP is stored as a hash, never in the clear. It is enough to show
 * that two changes came from the same place, which is what an
 * investigation needs, without keeping a personal identifier that
 * GDPR then obliges us to justify, expire and export.
 */
final class AuditEntry {

	/**
	 * Construct.
	 *
	 * @param string               $action     Dotted action name.
	 * @param int|null             $userId     WordPress user id.
	 * @param string|null          $objectType What kind of thing changed.
	 * @param int|null             $objectId   Which one.
	 * @param array<string, mixed> $changes    Redacted before it gets here.
	 * @param string|null          $ipHash     Hashed request IP.
	 * @param string|null          $userAgent  Truncated user agent.
	 * @param string               $createdAt  UTC timestamp.
	 */
	public function __construct(
		public readonly string $action,
		public readonly ?int $userId = null,
		public readonly ?string $objectType = null,
		public readonly ?int $objectId = null,
		public readonly array $changes = array(),
		public readonly ?string $ipHash = null,
		public readonly ?string $userAgent = null,
		public readonly string $createdAt = ''
	) {
	}

	/**
	 * Whether this entry describes a security-relevant change.
	 *
	 * Credential and capability changes are the ones worth surfacing
	 * first in a long log, so the UI can lead with them rather than
	 * burying them under routine edits.
	 *
	 * @return bool
	 */
	public function isSensitive(): bool {
		foreach ( array( 'provider.', 'licence.', 'privacy.', 'purge' ) as $prefix ) {
			if ( str_starts_with( $this->action, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
