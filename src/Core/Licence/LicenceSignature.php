<?php
/**
 * Verifies that a licence answer came from our licence server.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Licence;

/**
 * Ed25519 verification of the licence server's responses (SEC-09, D15 §11).
 *
 * ## Why a signature and not just TLS
 *
 * TLS with certificate verification is the primary control and it is
 * enforced in {@see LicenceClient}. This sits underneath it and catches the
 * cases TLS alone does not: an intercepting proxy on a corporate network
 * whose CA the site has been made to trust, a poisoned DNS answer paired
 * with such a CA, or a caching layer that replays a stale body. In each of
 * those the transport looks healthy and the payload is not ours.
 *
 * ## Asymmetric, deliberately
 *
 * The obvious implementation is an HMAC with a shared secret, and it is
 * worthless here. Verification happens on the customer's own machine, so the
 * secret would ship inside the plugin — where anyone who can read
 * `wp-config.php`, which is every customer, could mint themselves an Agency
 * licence that verified perfectly. Ed25519 separates verifying from signing:
 * this half can only check, and only the licence server can produce a
 * signature that passes.
 *
 * ## A failed check is `unreachable`, never `invalid`
 *
 * A response we cannot authenticate tells us nothing about the licence, so
 * it must not be allowed to decide anything about it. Reporting it as
 * unreachable means the answer is discarded whole: a forged upgrade is
 * ignored, and a customer whose network mangles the response keeps the
 * entitlements they already had. Reporting it as invalid would let anyone
 * able to interfere with a customer's traffic switch their paid features off.
 */
final class LicenceSignature {

	/**
	 * How far out of date a signed answer may be, in seconds.
	 *
	 * Wide enough for a slow request and a badly-set server clock, short
	 * enough that a captured "active" answer cannot be replayed to a site
	 * whose licence was revoked months ago.
	 */
	private const MAX_AGE = 900;

	/**
	 * The licence server's Ed25519 public key, as shipped.
	 *
	 * Safe to publish — it can only verify. This is the half of the pair the
	 * plugin needs and the half an attacker gains nothing from; the licence
	 * server holds the other half and is the only thing that can sign.
	 *
	 * Baked in rather than fetched. A key retrieved at runtime is a key an
	 * attacker who can already interfere with the response can also replace,
	 * which would make the whole check circular — the point of shipping it
	 * inside the plugin is that it arrives by a path the licence response
	 * cannot influence.
	 *
	 * Rotating this means shipping a plugin release. There is deliberately no
	 * second-key window: until one exists, a rotation breaks verification for
	 * every install that has not updated, and because a failed verification
	 * reports as `unreachable` they keep working rather than downgrading.
	 * That is survivable and it is not free, so the key is not to be changed
	 * casually.
	 */
	private const RELEASE_PUBLIC_KEY = 'lk+YKEx7iqg48b0BIHPfJ0PqWAJ0NqhXdOszua7owJM=';

	/**
	 * Whether a public key is configured at all.
	 *
	 * @return bool
	 */
	public static function isConfigured(): bool {
		return '' !== self::publicKey();
	}

	/**
	 * Whether libsodium is available to check a signature with.
	 *
	 * Reported separately from {@see self::isConfigured()} because the two
	 * have different fixes: a missing key is a build problem and belongs to
	 * us, while a missing extension is the host's and the operator can have
	 * it turned on. Collapsing them into one boolean would tell an operator
	 * that something is wrong without telling them whose.
	 *
	 * @return bool
	 */
	public static function isSupported(): bool {
		return function_exists( 'sodium_crypto_sign_verify_detached' );
	}

	/**
	 * Whether signatures are actually being checked on this install.
	 *
	 * This is the condition {@see self::verify()} short-circuits on, read
	 * rather than restated. A status screen that computed the same answer
	 * from its own copy of the rule would keep reporting "verifying" for
	 * however long it took the copies to drift apart, and the whole value
	 * of reporting it is that it is true.
	 *
	 * @return bool
	 */
	public static function isVerifying(): bool {
		return self::isConfigured() && self::isSupported();
	}

	/**
	 * Check the signature on a decoded response body.
	 *
	 * Returns true when there is nothing to check — no configured public
	 * key, or a PHP build without libsodium. That is a deliberate default:
	 * this is defence in depth behind TLS, and failing closed on it would
	 * turn one bad release of the *server's* key material into every
	 * customer's licence going unverifiable at once.
	 *
	 * What it costs is that such an install drops back to trusting TLS
	 * alone. That used to be described here as something the status screen
	 * reported, and it was not: {@see self::isConfigured()} had no callers
	 * anywhere in the plugin. The mitigation now exists — `/system/health`
	 * carries {@see self::isVerifying()} and the System Status screen shows
	 * it — because a silent fallback whose only mitigation is a comment is
	 * an undefended fallback.
	 *
	 * @param array<string, mixed> $body Decoded JSON.
	 * @param int                  $now  Current UNIX time.
	 * @return bool
	 */
	public static function verify( array $body, int $now ): bool {
		if ( ! self::isVerifying() ) {
			return true;
		}

		$key = self::publicKey();

		// Unreachable: isVerifying() has already established the key is
		// non-empty. Kept because the alternative is asserting the type to
		// the analyser, and an assertion is a promise while this is a check.
		if ( '' === $key ) {
			return true;
		}

		$signature = is_string( $body['signature'] ?? null ) ? base64_decode( $body['signature'], true ) : false;

		if ( ! is_string( $signature ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
			return false;
		}

		$signedAt = is_numeric( $body['signed_at'] ?? null ) ? (int) $body['signed_at'] : 0;

		// `signed_at` is inside the signed material, so this bounds replay
		// rather than merely reading a field an attacker controls.
		if ( 0 === $signedAt || abs( $now - $signedAt ) > self::MAX_AGE ) {
			return false;
		}

		try {
			return sodium_crypto_sign_verify_detached( $signature, self::canonical( $body ), $key );
		} catch ( \SodiumException $e ) {
			unset( $e );

			return false;
		}
	}

	/**
	 * The byte-exact form the server signed.
	 *
	 * Must match `AppointivaLicenseServer\Support\Signer::canonical()`
	 * exactly: signature removed, keys sorted, slashes and unicode left
	 * unescaped. Any drift between the two shows up as every signature
	 * failing rather than as a subtle hole — the right direction for
	 * something a test would otherwise have to be written to notice.
	 *
	 * @param array<string, mixed> $body Decoded JSON.
	 * @return string
	 */
	private static function canonical( array $body ): string {
		unset( $body['signature'] );
		ksort( $body );

		return (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * The licence server's public key, raw bytes, or an empty string.
	 *
	 * @return string
	 */
	private static function publicKey(): string {
		$configured = defined( 'HIVECLERK_LICENCE_PUBLIC_KEY' )
			? (string) constant( 'HIVECLERK_LICENCE_PUBLIC_KEY' )
			: self::RELEASE_PUBLIC_KEY;

		/**
		 * Filter the licence server's Ed25519 public key, base64 encoded.
		 *
		 * Filterable alongside `hiveclerk/licence/endpoint` so a staging
		 * environment can point at its own licence server without a code
		 * change — the two have to move together, since a different server
		 * signs with a different key.
		 *
		 * @param string $key Base64 public key.
		 */
		$configured = (string) apply_filters( 'hiveclerk/licence/public_key', $configured );

		if ( '' === $configured ) {
			return '';
		}

		$decoded = base64_decode( $configured, true );

		return is_string( $decoded ) && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $decoded ) ? $decoded : '';
	}
}
