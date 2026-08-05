<?php
/**
 * One-click unsubscribe tokens.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Email\Services;

use Hiveclerk\Domain\Lead\Lead;

/**
 * The link at the bottom of every email (FR-EML-06).
 *
 * ## Why the token is derived rather than stored
 *
 * A stored token is a row per recipient per email that has to be created
 * before sending, looked up on click, and kept forever — because an
 * unsubscribe link in a message from two years ago still has to work. An
 * HMAC over the address hash needs no row at all and cannot expire, which
 * is the correct behaviour here: an unsubscribe link that stops working
 * is a compliance failure, not a security improvement.
 *
 * ## Why it is not just the email address
 *
 * A link carrying a plain address lets anybody unsubscribe anybody by
 * editing a URL, and it puts a real address into every proxy log and
 * browser history the link passes through. The HMAC proves this site
 * issued the link without revealing who it names.
 *
 * The key is derived from the site's WordPress salts. Regenerating salts
 * invalidates outstanding links, which is the one real cost — and a site
 * doing that has bigger problems than a stale unsubscribe link.
 */
final class UnsubscribeTokens {

	/**
	 * Build the token for an address.
	 *
	 * @param string $email Address in any casing.
	 * @return string|null Null when the address is not one.
	 */
	public function forEmail( string $email ): ?string {
		$hash = Lead::hashEmail( $email );

		return null === $hash ? null : $hash . '.' . $this->sign( $hash );
	}

	/**
	 * Verify a token and return the address hash it names.
	 *
	 * @param string $token Token from the link.
	 * @return string|null Null when the token is not ours.
	 */
	public function verify( string $token ): ?string {
		$parts = explode( '.', $token, 2 );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		[ $hash, $signature ] = $parts;

		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
			return null;
		}

		// Constant time. A timing oracle here would let somebody recover a
		// valid signature for a hash they chose, and the hash of a guessed
		// address is trivially computable.
		return hash_equals( $this->sign( $hash ), $signature ) ? $hash : null;
	}

	/**
	 * The unsubscribe URL for an address.
	 *
	 * @param string $email Address.
	 * @return string|null
	 */
	public function url( string $email ): ?string {
		$token = $this->forEmail( $email );

		return null === $token
			? null
			: rest_url( 'hiveclerk/v1/public/unsubscribe' ) . '?token=' . rawurlencode( $token );
	}

	/**
	 * Sign one address hash.
	 *
	 * @param string $hash Address hash.
	 * @return string
	 */
	private function sign( string $hash ): string {
		return hash_hmac( 'sha256', 'unsubscribe:' . $hash, $this->key() );
	}

	/**
	 * The signing key.
	 *
	 * @return string
	 */
	private function key(): string {
		$salts = ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' )
			. ( defined( 'NONCE_KEY' ) ? (string) NONCE_KEY : '' );

		return hash_hkdf( 'sha256', $salts, 32, 'hiveclerk-unsubscribe-v1' );
	}
}
