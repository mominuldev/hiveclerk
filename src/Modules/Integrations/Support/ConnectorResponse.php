<?php
/**
 * One HTTP response from a connector's API.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Support;

/**
 * A decoded response, with the retry decision already made.
 *
 * `status === 0` means the request never reached anyone — a DNS failure,
 * a refused connection, a blocked URL. It is kept distinct from a 5xx
 * because the two look identical to a caller that only checks for
 * "not 2xx" and mean different things to whoever reads the sync log.
 */
final readonly class ConnectorResponse {

	/**
	 * Construct.
	 *
	 * @param int                 $status HTTP status, or 0 for a transport failure.
	 * @param array<string,mixed> $body   Decoded JSON body, empty when there was none.
	 * @param string              $raw    Raw body, kept for error messages.
	 */
	public function __construct(
		public int $status,
		public array $body = array(),
		public string $raw = ''
	) {
	}

	/**
	 * Whether the far side accepted the request.
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Whether trying again later could plausibly work.
	 *
	 * 408, 429 and every 5xx are the provider's problem and pass. A 401
	 * passes too, because the most common cause is an access token that
	 * expired between the refresh check and the call — the next attempt
	 * refreshes first. Everything else in the 4xx range is our request
	 * being wrong, and repeating it five times over fourteen hours only
	 * fills the customer's log with the same failure.
	 *
	 * @return bool
	 */
	public function isRetryable(): bool {
		if ( 0 === $this->status ) {
			return true;
		}

		if ( in_array( $this->status, array( 401, 408, 429 ), true ) ) {
			return true;
		}

		return $this->status >= 500;
	}

	/**
	 * A value from the decoded body.
	 *
	 * @param string $key Top-level key.
	 * @return mixed
	 */
	public function get( string $key ): mixed {
		return $this->body[ $key ] ?? null;
	}

	/**
	 * A string value from the decoded body.
	 *
	 * @param string $key Top-level key.
	 * @return string
	 */
	public function string( string $key ): string {
		$value = $this->body[ $key ] ?? null;

		if ( is_string( $value ) ) {
			return $value;
		}

		return is_int( $value ) || is_float( $value ) ? (string) $value : '';
	}

	/**
	 * The best error message this response carries.
	 *
	 * Providers disagree about where to put it, and an operator reading
	 * "Request failed with status 400" learns nothing they could act on.
	 *
	 * @return string
	 */
	public function errorMessage(): string {
		foreach ( array( 'message', 'error_description', 'error', 'detail' ) as $key ) {
			$value = $this->body[ $key ] ?? null;

			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		if ( 0 === $this->status ) {
			return '' !== $this->raw ? $this->raw : 'The request never reached the provider.';
		}

		return sprintf( 'The provider answered %d.', $this->status );
	}
}
