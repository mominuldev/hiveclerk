<?php
/**
 * Suppression reason.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Email;

/**
 * Why an address is never written to again (FR-EML-06).
 *
 * The reason is kept because the three are not the same obligation. An
 * unsubscribe is the recipient's instruction and is permanent. A bounce
 * is a fact about a mailbox and could in principle be reviewed. A manual
 * block is the operator's own decision. Storing only "suppressed" would
 * make it impossible to answer the one question that matters when
 * somebody asks why they stopped receiving email.
 */
enum SuppressionReason: string {

	case Unsubscribed = 'unsubscribed';
	case Bounced      = 'bounced';
	case Complained   = 'complained';
	case Manual       = 'manual';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Unsubscribed => 'Unsubscribed',
			self::Bounced      => 'Bounced',
			self::Complained   => 'Marked as spam',
			self::Manual       => 'Blocked by hand',
		};
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Manual;
	}
}
