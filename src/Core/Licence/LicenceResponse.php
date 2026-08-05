<?php
/**
 * What the licence server said.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Licence;

use DateTimeImmutable;

/**
 * One answer from the licence API, parsed and nothing more.
 *
 * Deliberately not a {@see Licence}: this is what a remote server
 * claimed, and the licence is what this site decided to store as a
 * result. Keeping them separate is what makes "the server said expired
 * but we are still inside the grace window" expressible.
 */
final class LicenceResponse {

	/**
	 * Construct.
	 *
	 * @param LicenceStatus          $status    Outcome.
	 * @param Tier                   $tier      Tier the key carries.
	 * @param int                    $sites     Seats in use.
	 * @param DateTimeImmutable|null $expiresAt Expiry.
	 * @param string|null            $customer  Account name.
	 * @param string|null            $message   Server-supplied explanation.
	 */
	public function __construct(
		public readonly LicenceStatus $status,
		public readonly Tier $tier = Tier::Free,
		public readonly int $sites = 0,
		public readonly ?DateTimeImmutable $expiresAt = null,
		public readonly ?string $customer = null,
		public readonly ?string $message = null
	) {
	}

	/**
	 * The server could not be asked.
	 *
	 * @param string $message What went wrong.
	 * @return self
	 */
	public static function unreachable( string $message ): self {
		return new self( LicenceStatus::Unreachable, Tier::Free, 0, null, null, $message );
	}

	/**
	 * Parse a decoded response body.
	 *
	 * The status is taken from the body rather than inferred from the
	 * HTTP code, because "this key exists and has run out of seats" is a
	 * successful request that reports a refusal, and reading it off a 200
	 * would make it look like an activation.
	 *
	 * @param array<string, mixed> $body Decoded JSON.
	 * @param int                  $code HTTP status.
	 * @return self
	 */
	public static function fromBody( array $body, int $code ): self {
		$status = LicenceStatus::fromStorage( is_string( $body['status'] ?? null ) ? $body['status'] : null );

		// A 4xx with no recognisable status is a refusal we cannot
		// interpret. Treated as invalid rather than unreachable: the
		// server answered, and it said no.
		if ( LicenceStatus::Inactive === $status && $code >= 400 ) {
			$status = LicenceStatus::Invalid;
		}

		return new self(
			$status,
			Tier::fromStorage( is_string( $body['tier'] ?? null ) ? $body['tier'] : null ),
			is_numeric( $body['sites'] ?? null ) ? max( 0, (int) $body['sites'] ) : 0,
			LicenceClient::date( $body['expires_at'] ?? null ),
			is_string( $body['customer'] ?? null ) ? sanitize_text_field( $body['customer'] ) : null,
			is_string( $body['message'] ?? null ) ? sanitize_text_field( $body['message'] ) : null
		);
	}

	/**
	 * Whether this answer should switch paid features on.
	 *
	 * @return bool
	 */
	public function isActivation(): bool {
		return LicenceStatus::Active === $this->status;
	}
}
