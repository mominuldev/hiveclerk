<?php
/**
 * Pre-flight check on any URL a customer supplied.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Infrastructure\Http;

/**
 * Refuses to let the server fetch its own private network (SEC-05).
 *
 * Any URL that arrives from a customer — a crawl source, a Slack webhook,
 * an outbound webhook in Sprint 8 — is a server-side request forgery
 * primitive. Point one at `http://169.254.169.254/` on a cloud host and
 * the response is the instance's credentials, fetched by our code, stored
 * in the customer's database.
 *
 * `wp_safe_remote_post()` alone is not enough. It blocks loopback and
 * RFC 1918 but **not** link-local, which is exactly where the cloud
 * metadata endpoint lives. `FILTER_FLAG_NO_RES_RANGE` covers link-local
 * along with 0.0.0.0/8, the reserved 240.0.0.0/4 block, and the IPv6
 * equivalents that WordPress's own check does not look at either.
 *
 * ## The gap this does not close
 *
 * This is a pre-flight check and therefore beatable by DNS rebinding — a
 * name that resolves to a public address here and a private one when the
 * socket opens. Closing that needs resolution and connection to happen
 * together, which the WordPress HTTP API does not expose. The gap is
 * narrow, it is documented, and it is far smaller than the one that
 * exists without this.
 *
 * Extracted from the crawler's PageFetcher, which had it first. It is
 * here because the second caller arrived — a webhook URL typed into a
 * settings field is the same primitive as a URL typed into a crawl form,
 * and a second copy of this logic is a second place for it to rot.
 */
final class OutboundUrlGuard {

	/**
	 * Whether a URL must not be fetched.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public function isBlocked( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return true;
		}

		// An IPv6 literal arrives wrapped in brackets.
		$host = trim( $host, '[]' );

		foreach ( $this->addressesFor( $host ) as $address ) {
			$public = filter_var(
				$address,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);

			if ( false === $public ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every address a host resolves to.
	 *
	 * All of them, not just the first: a name with several A records is
	 * only safe if every one of them is, and a resolver is free to return
	 * them in any order.
	 *
	 * @param string $host Host name or IP literal.
	 * @return array<int, string>
	 */
	private function addressesFor( string $host ): array {
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$addresses = array();

		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( isset( $record['ip'] ) && is_string( $record['ip'] ) ) {
					$addresses[] = $record['ip'];
				}

				if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
					$addresses[] = $record['ipv6'];
				}
			}
		}

		if ( array() !== $addresses ) {
			return $addresses;
		}

		// dns_get_record can fail where the system resolver succeeds.
		// Falling back keeps ordinary hosts reachable; a name that
		// resolves to nothing at all is left to the HTTP layer to refuse.
		$resolved = gethostbyname( $host );

		return $resolved === $host ? array() : array( $resolved );
	}
}
