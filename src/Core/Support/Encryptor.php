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
 * The key is derived from a per-install random salt held in its own option
 * plus the site's WordPress salts. Both must be stolen together for
 * ciphertext to be readable, so a leaked database dump alone does not
 * expose the customer's provider keys.
 *
 * ## Why there are two derivations
 *
 * `v1` used the WordPress salts as the HKDF *key* and the per-install salt
 * as the HKDF salt. `hash_hkdf()` rejects an empty key, so an install whose
 * `AUTH_KEY`, `SECURE_AUTH_KEY` and `LOGGED_IN_KEY` were all blank — which
 * happens after some migrations and on some mis-provisioned hosts — threw
 * an uncaught `ValueError` on *every* read and write of a provider key. The
 * failure surfaced as a fatal pointing at a hash function rather than at
 * the configuration that caused it, and the product was unusable.
 *
 * `v2` swaps them. The per-install salt is the key material and is always
 * present, because `salt()` generates it on first use; the WordPress salts
 * become the HKDF salt, which RFC 5869 explicitly allows to be empty. A
 * site with no salts defined therefore keeps working, deriving from the
 * per-install value alone. That is weaker — a database dump then carries
 * everything needed to read the ciphertext — but it is a working install
 * with a recorded caveat instead of a fatal one, and a site with its salts
 * defined (which is all of them, normally) still needs both halves.
 *
 * Both versions are readable. Only `v2` is written, so a secret upgrades
 * the next time it is saved and no migration has to walk the stores.
 */
final class Encryptor {

	private const CIPHER      = 'aes-256-gcm';
	private const SALT_OPTION = 'hiveclerk_encryption_salt';

	/**
	 * The salt being retired, readable but never written with.
	 *
	 * Present only while a rotation is in progress. Rotating the salt makes
	 * every stored secret unreadable the instant it changes, so the old one
	 * has to outlive it until the last ciphertext has been rewritten —
	 * otherwise "rotate the key" means "lose every provider key", which is
	 * an operator's worst day rather than their recovery from one.
	 */
	private const SALT_PREVIOUS_OPTION = 'hiveclerk_encryption_salt_previous';
	private const VERSION              = 'v2';
	private const LEGACY_VERSION       = 'v1';
	private const TAG_LENGTH           = 16;

	/**
	 * Shortest secret whose middle can be hidden without revealing the ends.
	 *
	 * The masked form shows seven leading and four trailing characters. At
	 * eleven characters or fewer those overlap, so the "mask" would be the
	 * secret itself — printed into an option and handed to the SPA. Below
	 * this length nothing is revealed at all.
	 */
	private const MASKABLE_LENGTH = 12;

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
		$key = $this->key( self::VERSION, $this->salt() );

		// The current derivation cannot fail — its key material is the
		// per-install salt, which is generated on demand. Checked anyway so
		// a future version that can fail cannot encrypt with a null key.
		if ( null === $key ) {
			throw new RuntimeException( 'Could not derive the encryption key.' );
		}

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
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
	 * Reads both derivations. A `v1` value written before the key material
	 * was swapped still decrypts, and is rewritten as `v2` when its owner
	 * next saves it.
	 *
	 * @param string $payload Ciphertext produced by encrypt().
	 * @return string|null
	 */
	public function decrypt( string $payload ): ?string {
		if ( '' === $payload ) {
			return null;
		}

		$parts = explode( ':', $payload, 2 );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$version = $parts[0];

		if ( self::VERSION !== $version && self::LEGACY_VERSION !== $version ) {
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

		/*
		 * The current salt first, then the one being retired.
		 *
		 * Trying two keys is safe rather than sloppy: GCM authenticates, so
		 * the wrong key fails the tag check and returns false instead of
		 * returning plausible rubbish. There is no oracle here either — both
		 * outcomes are the same `null` to the caller.
		 *
		 * Both versions get the fallback, not just v2. The v1 derivation
		 * takes the per-install salt as its HKDF salt, so rotating strands
		 * v1 ciphertext exactly as it strands v2.
		 */
		foreach ( $this->saltsToTry() as $installSalt ) {
			$key = $this->key( $version, $installSalt );

			if ( null === $key ) {
				continue;
			}

			$plaintext = openssl_decrypt(
				$ciphertext,
				self::CIPHER,
				$key,
				OPENSSL_RAW_DATA,
				$iv,
				$tag
			);

			if ( false !== $plaintext ) {
				return $plaintext;
			}
		}

		return null;
	}

	/**
	 * Whether this ciphertext is already written under the current salt.
	 *
	 * Lets a rotation sweep skip what it has already done, so an interrupted
	 * run resumes instead of restarting — and so re-running it on a finished
	 * install is free rather than a full rewrite of every secret.
	 *
	 * @param string $payload Ciphertext.
	 * @return bool
	 */
	public function isCurrent( string $payload ): bool {
		if ( ! str_starts_with( $payload, self::VERSION . ':' ) ) {
			return false;
		}

		$previous = get_option( self::SALT_PREVIOUS_OPTION );

		// With no rotation running, anything that reads at all is current.
		if ( ! is_string( $previous ) || '' === $previous ) {
			return null !== $this->decrypt( $payload );
		}

		return null !== $this->openWith( $payload, $this->salt() );
	}

	/**
	 * Begin a rotation: retire the current salt and mint a new one.
	 *
	 * Refuses while a rotation is already in progress. A second rotation
	 * would push the oldest salt off the end of the two we can hold, and
	 * every secret not yet rewritten under the first one would become
	 * permanently unreadable — silently, because an unreadable key reads as
	 * "not configured".
	 *
	 * @return bool False when a rotation is already under way.
	 */
	public function beginRotation(): bool {
		if ( $this->isRotating() ) {
			return false;
		}

		$current = $this->salt();

		update_option( self::SALT_PREVIOUS_OPTION, $current, false );
		update_option( self::SALT_OPTION, bin2hex( random_bytes( 32 ) ), false );

		return true;
	}

	/**
	 * Whether a rotation is in progress.
	 *
	 * @return bool
	 */
	public function isRotating(): bool {
		$previous = get_option( self::SALT_PREVIOUS_OPTION );

		return is_string( $previous ) && '' !== $previous;
	}

	/**
	 * Drop the retired salt, ending the window.
	 *
	 * Only safe once every stored secret has been rewritten. Anything still
	 * encrypted under the old salt becomes unreadable here, which is the
	 * point — that is what makes the old key material worthless — and is why
	 * the caller has to have finished sweeping first.
	 *
	 * @return void
	 */
	public function finishRotation(): void {
		delete_option( self::SALT_PREVIOUS_OPTION );
	}

	/**
	 * The install salts to attempt, current first.
	 *
	 * @return list<string>
	 */
	private function saltsToTry(): array {
		$salts    = array( $this->salt() );
		$previous = get_option( self::SALT_PREVIOUS_OPTION );

		if ( is_string( $previous ) && '' !== $previous && $previous !== $salts[0] ) {
			$salts[] = $previous;
		}

		return $salts;
	}

	/**
	 * Decrypt against one specific install salt.
	 *
	 * @param string $payload     Ciphertext.
	 * @param string $installSalt Salt to derive with.
	 * @return string|null
	 */
	private function openWith( string $payload, string $installSalt ): ?string {
		$parts = explode( ':', $payload, 2 );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$key    = $this->key( $parts[0], $installSalt );
		$binary = base64_decode( $parts[1], true );

		if ( null === $key || false === $binary ) {
			return null;
		}

		$ivLength = openssl_cipher_iv_length( self::CIPHER );

		if ( false === $ivLength || strlen( $binary ) <= $ivLength + self::TAG_LENGTH ) {
			return null;
		}

		$plaintext = openssl_decrypt(
			substr( $binary, $ivLength + self::TAG_LENGTH ),
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			substr( $binary, 0, $ivLength ),
			substr( $binary, $ivLength, self::TAG_LENGTH )
		);

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * A display form that proves a key is set without revealing it.
	 *
	 * A short secret is shown as a fixed run of bullets rather than a run
	 * matching its length, because the length of a credential is itself a
	 * hint worth withholding.
	 *
	 * @param string $plaintext Secret.
	 * @return string
	 */
	public function mask( #[SensitiveParameter] string $plaintext ): string {
		if ( strlen( $plaintext ) < self::MASKABLE_LENGTH ) {
			return str_repeat( '•', 8 );
		}

		return substr( $plaintext, 0, 7 ) . str_repeat( '•', 8 ) . substr( $plaintext, -4 );
	}

	/**
	 * Derive the 256-bit encryption key for one ciphertext version.
	 *
	 * @param string $version     Ciphertext version prefix.
	 * @param string $installSalt Per-install salt to derive from.
	 * @return string|null Null when this install cannot rebuild that key.
	 */
	private function key( string $version, string $installSalt ): ?string {
		if ( self::LEGACY_VERSION === $version ) {
			$salts = $this->wordpressSalts();

			/*
			 * v1 derived from the WordPress salts, and `hash_hkdf()` rejects
			 * empty key material. With none defined there is no key to
			 * rebuild, so the value reads as unreadable — which callers
			 * already handle — rather than fataling the request, which is
			 * the bug this version exists to leave behind.
			 */
			if ( '' === $salts ) {
				return null;
			}

			return hash_hkdf( 'sha256', $salts, 32, 'hiveclerk-secret-v1', $installSalt );
		}

		return hash_hkdf( 'sha256', $installSalt, 32, 'hiveclerk-secret-v2', $this->wordpressSalts() );
	}

	/**
	 * The site's authentication salts, concatenated.
	 *
	 * @return string Empty when none are defined.
	 */
	private function wordpressSalts(): string {
		return ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '' )
			. ( defined( 'LOGGED_IN_KEY' ) ? (string) LOGGED_IN_KEY : '' );
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
