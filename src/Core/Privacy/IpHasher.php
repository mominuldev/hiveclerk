<?php
/**
 * The one place a visitor's address becomes a hash.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Privacy;

/**
 * Turns the requesting address into a stored value, or into nothing.
 *
 * Promoted from two identical private methods — one in `VisitorService`,
 * one in `SessionService`. Two copies of a privacy control is one that
 * honours the site's setting and one that quietly does not, and the one
 * that does not is the one nobody looks at.
 *
 * The address is salted and hashed here and the original is never
 * returned, stored or logged, whatever the setting says. What the setting
 * controls is whether even the hash is kept: a site that has no use for
 * per-visitor forensics can hold nothing derived from an address at all.
 *
 * Rate limiting does not come through here. It derives its own key from
 * the live request and has never read the stored column, so turning this
 * off costs a site nothing in abuse protection — which is the only reason
 * it can be offered as a choice rather than as a trade-off.
 *
 * The admin audit log does not come through here either. Its IP belongs
 * to an administrator acting on the site, not to a visitor being tracked
 * by it, and it is kept for exactly the case where somebody needs to
 * reconstruct who changed the API key.
 */
final class IpHasher {

	/**
	 * Construct.
	 *
	 * @param PrivacySettings $privacy Privacy preferences.
	 */
	public function __construct( private readonly PrivacySettings $privacy ) {
	}

	/**
	 * A salted hash of the caller's address, or null.
	 *
	 * Null for three different reasons — the site turned storage off,
	 * there is no address (WP-CLI, cron), or what arrived is not an
	 * address — and the caller does not need to tell them apart, because
	 * the column is nullable and every one of them means the same thing:
	 * nothing derived from an address gets written.
	 *
	 * @return string|null
	 */
	public function hash(): ?string {
		if ( ! $this->privacy->storesIpHash() ) {
			return null;
		}

		$remote = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		if ( ! is_string( $remote ) ) {
			return null;
		}

		$ip = filter_var( wp_unslash( $remote ), FILTER_VALIDATE_IP );

		if ( ! is_string( $ip ) ) {
			return null;
		}

		/*
		 * Salted with AUTH_SALT so the digests are not comparable against
		 * a rainbow table of the whole IPv4 space, which is small enough
		 * to enumerate — an unsalted SHA-256 of an IP address is a
		 * reversible identifier wearing a hash's clothes.
		 */
		$salt = defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '';

		return hash( 'sha256', $salt . '|' . $ip );
	}
}
