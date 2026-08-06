<?php
/**
 * Licence server transport.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Licence;

use DateTimeImmutable;
use DateTimeZone;
use SensitiveParameter;

/**
 * Talks to the licence API (D16 §7).
 *
 * Self-hosted rather than Freemius, so the customer relationship and the
 * data stay with us and there is no perpetual revenue share on every
 * sale. Checkout is a merchant of record; only activation, seats and
 * updates come through here.
 *
 * ## Failure is not a downgrade
 *
 * Every failure path returns {@see LicenceStatus::Unreachable}, never
 * `Invalid`. A timeout, a DNS failure, a 502 at our end or a customer's
 * outbound firewall must not read as "this key is fake" — that would turn
 * an outage on our side into a support ticket on theirs, and the ticket
 * would say the plugin deactivated itself.
 *
 * The distinction matters more than it looks: `Invalid` is a decision
 * about the customer, and we should only make it when a server we
 * authenticated actually said so.
 */
final class LicenceClient {

	/**
	 * Where activation requests go.
	 *
	 * Filterable so a test environment, and eventually the V3 SaaS, can
	 * point somewhere else without a code change.
	 */
	public const ENDPOINT = 'https://licence.hiveclerk.com/v1';

	/**
	 * Seconds to wait on the network.
	 *
	 * Short. This runs inside an admin request when an operator clicks
	 * Activate, and a request that hangs for thirty seconds is one the
	 * operator has already refreshed away from.
	 */
	private const TIMEOUT = 10;

	/**
	 * Activate a key against this site.
	 *
	 * @param string $key  Licence key.
	 * @param string $site Site URL.
	 * @return LicenceResponse
	 */
	public function activate( #[SensitiveParameter] string $key, string $site ): LicenceResponse {
		return $this->post( 'activate', $key, $site );
	}

	/**
	 * Release this site's seat.
	 *
	 * @param string $key  Licence key.
	 * @param string $site Site URL.
	 * @return LicenceResponse
	 */
	public function deactivate( #[SensitiveParameter] string $key, string $site ): LicenceResponse {
		return $this->post( 'deactivate', $key, $site );
	}

	/**
	 * Re-check a key that is already active here.
	 *
	 * @param string $key  Licence key.
	 * @param string $site Site URL.
	 * @return LicenceResponse
	 */
	public function check( #[SensitiveParameter] string $key, string $site ): LicenceResponse {
		return $this->post( 'check', $key, $site );
	}

	/**
	 * One request.
	 *
	 * @param string $action One of activate|deactivate|check.
	 * @param string $key    Licence key.
	 * @param string $site   Site URL.
	 * @return LicenceResponse
	 */
	private function post( string $action, #[SensitiveParameter] string $key, string $site ): LicenceResponse {
		/**
		 * Filter the licence API base URL.
		 *
		 * @param string $endpoint Base URL.
		 */
		$endpoint = (string) apply_filters( 'hiveclerk/licence/endpoint', self::ENDPOINT );

		$response = wp_remote_post(
			trailingslashit( $endpoint ) . $action,
			array(
				'timeout'     => self::TIMEOUT,
				// Explicit rather than relying on the default. A licence
				// request carries a key that identifies a paying customer,
				// and a site with a broken CA bundle should fail rather
				// than send it over an unverified connection.
				'sslverify'   => true,
				// The body carries the customer's licence key, and
				// WordPress follows five redirects by default — replaying
				// that body to each new location. The endpoint is a fixed
				// HTTPS URL, so reaching a hostile one means the filter or
				// the server itself is already compromised; this is the
				// same standard every other outbound call in the codebase
				// now holds, and there is no legitimate redirect to lose.
				'redirection' => 0,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'        => (string) wp_json_encode(
					array(
						'key'     => $key,
						'site'    => $site,
						'version' => HIVECLERK_VERSION,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return LicenceResponse::unreachable( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return LicenceResponse::unreachable( 'The licence server sent something unreadable.' );
		}

		if ( $code >= 500 ) {
			return LicenceResponse::unreachable( 'The licence server is having trouble.' );
		}

		// Authenticity before interpretation. An answer we cannot attribute
		// to our own server is discarded whole rather than read for the parts
		// that look sensible — otherwise anyone able to interfere with the
		// customer's traffic decides their tier. Unreachable, not invalid:
		// see LicenceSignature for why that direction matters.
		if ( ! LicenceSignature::verify( $body, time() ) ) {
			return LicenceResponse::unreachable( 'The licence server\'s answer could not be verified.' );
		}

		return LicenceResponse::fromBody( $body, $code );
	}

	/**
	 * Parse a date the server sent, or null.
	 *
	 * @param mixed $value Raw value.
	 * @return DateTimeImmutable|null
	 */
	public static function date( mixed $value ): ?DateTimeImmutable {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			unset( $e );

			return null;
		}
	}
}
