<?php
/**
 * Secret encryption.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Support;

use RuntimeException;
use SensitiveParameter;

/**
 * AES-256-GCM encryption for API keys and OAuth tokens.
 *
 * GCM rather than CBC because it is authenticated: a tampered ciphertext
 * fails to decrypt instead of yielding attacker-influenced plaintext.
 *
 * The key is derived from the site's WordPress salts plus a per-install
 * random salt held in a separate option. Both must be stolen together for
 * ciphertext to be readable, so a leaked database dump alone does not
 * expose the customer's provider keys.
 */
final class Encryptor {

	private const CIPHER      = 'aes-256-gcm';
	private const SALT_OPTION = 'hiveclerk_encryption_salt';
	private const VERSION     = 'v1';
	private const TAG_LENGTH  = 16;

	/**
	 * Encrypt a secret.
	 *
	 * @param string $plaintext Value to protect.
	 * @return string Portable ciphertext string.
	 *
	 * @throws RuntimeException When encryption fails.
	 */
	public function encrypt( #[SensitiveParameter] string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$ivLength = openssl_cipher_iv_length( self::CIPHER );

		// A zero-length IV would silently produce deterministic ciphertext,
		// so an unusable cipher must fail loudly rather than encrypt badly.
		if ( false === $ivLength || $ivLength < 1 ) {
			throw new RuntimeException( 'AES-256-GCM is unavailable on this server.' );
		}

		$iv  = random_bytes( $ivLength );
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$this->key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LENGTH
		);

		if ( false === $ciphertext ) {
			throw new RuntimeException( 'Could not encrypt the value.' );
		}

		return self::VERSION . ':' . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a secret.
	 *
	 * Returns null rather than throwing on tampered or corrupt input: a
	 * caller should treat an unreadable key as "not configured" and prompt
	 * for a new one, not fatal the request.
	 *
	 * @param string $payload Ciphertext produced by encrypt().
	 * @return string|null
	 */
	public function decrypt( string $payload ): ?string {
		if ( '' === $payload ) {
			return null;
		}

		$parts = explode( ':', $payload, 2 );

		if ( 2 !== count( $parts ) || self::VERSION !== $parts[0] ) {
			return null;
		}

		$binary = base64_decode( $parts[1], true );

		if ( false === $binary ) {
			return null;
		}

		$ivLength = openssl_cipher_iv_length( self::CIPHER );

		if ( false === $ivLength || strlen( $binary ) <= $ivLength + self::TAG_LENGTH ) {
			return null;
		}

		$iv         = substr( $binary, 0, $ivLength );
		$tag        = substr( $binary, $ivLength, self::TAG_LENGTH );
		$ciphertext = substr( $binary, $ivLength + self::TAG_LENGTH );

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$this->key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * A display form that proves a key is set without revealing it.
	 *
	 * @param string $plaintext Secret.
	 * @return string
	 */
	public function mask( #[SensitiveParameter] string $plaintext ): string {
		$length = strlen( $plaintext );

		if ( $length <= 8 ) {
			return str_repeat( '•', max( 4, $length ) );
		}

		return substr( $plaintext, 0, 7 ) . str_repeat( '•', 8 ) . substr( $plaintext, -4 );
	}

	/**
	 * Derive the 256-bit encryption key.
	 *
	 * @return string
	 */
	private function key(): string {
		$salts = ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '' )
			. ( defined( 'LOGGED_IN_KEY' ) ? (string) LOGGED_IN_KEY : '' );

		return hash_hkdf( 'sha256', $salts, 32, 'hiveclerk-secret-v1', $this->salt() );
	}

	/**
	 * Per-install random salt, created on first use.
	 *
	 * Autoload is off: this value is read only when a secret is handled,
	 * never on a front-end page load.
	 *
	 * @return string
	 */
	private function salt(): string {
		$salt = get_option( self::SALT_OPTION );

		if ( is_string( $salt ) && '' !== $salt ) {
			return $salt;
		}

		$salt = bin2hex( random_bytes( 32 ) );

		add_option( self::SALT_OPTION, $salt, '', false );

		return $salt;
	}
}
