<?php
/**
 * Connector credentials.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

use DateTimeImmutable;
use LogicException;
use SensitiveParameter;

/**
 * What one connector needs to authenticate, in plaintext.
 *
 * A bag rather than named properties because the four connectors need
 * genuinely different things — FluentCRM needs nothing at all, a webhook
 * needs a URL and a signing secret, HubSpot needs an access token, a
 * refresh token and an expiry. Naming all of them here would produce a
 * value object where most fields are null on every instance.
 *
 * Non-serialisable on purpose, exactly as `Ai\Credentials` is: __sleep()
 * throwing means an access token cannot reach a transient, a queued job
 * payload or a debug log through an accidental serialize(). Job payloads
 * carry the integration id and the job reads the token back out of
 * storage, which is a deliberate extra round trip.
 */
final class ConnectorCredentials {

	/**
	 * Construct.
	 *
	 * @param array<string, string>  $values    Secret material, key by key.
	 * @param DateTimeImmutable|null $expiresAt When the access token dies, UTC.
	 */
	public function __construct(
		#[SensitiveParameter]
		private readonly array $values = array(),
		public readonly ?DateTimeImmutable $expiresAt = null
	) {
	}

	/**
	 * Nothing stored.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self();
	}

	/**
	 * One value, or the empty string.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	public function get( string $key ): string {
		$value = $this->values[ $key ] ?? '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Whether a field holds anything.
	 *
	 * @param string $key Field name.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return '' !== trim( $this->get( $key ) );
	}

	/**
	 * Whether anything at all is stored.
	 *
	 * @return bool
	 */
	public function isPresent(): bool {
		foreach ( $this->values as $value ) {
			if ( '' !== trim( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The whole bag, for the encryptor on the way to storage.
	 *
	 * @return array<string, string>
	 */
	public function toArray(): array {
		return $this->values;
	}

	/**
	 * A copy with some fields replaced.
	 *
	 * Used by the refresh flow, which gets a new access token and an
	 * expiry back while the refresh token stays as it was.
	 *
	 * @param array<string, string>  $values    Fields to overwrite.
	 * @param DateTimeImmutable|null $expiresAt New expiry, UTC.
	 * @return self
	 */
	public function with( #[SensitiveParameter] array $values, ?DateTimeImmutable $expiresAt = null ): self {
		return new self(
			array_merge( $this->values, $values ),
			$expiresAt ?? $this->expiresAt
		);
	}

	/**
	 * Whether the access token has expired, or is about to.
	 *
	 * The skew matters more than it looks. A token checked as valid and
	 * then used two seconds later against a provider whose clock runs
	 * fast fails with a 401 that the retry policy reads as a transient
	 * fault and repeats five times over fourteen hours.
	 *
	 * @param DateTimeImmutable $now  Current time, UTC.
	 * @param int               $skew Seconds of headroom.
	 * @return bool
	 */
	public function isExpired( DateTimeImmutable $now, int $skew = 120 ): bool {
		if ( null === $this->expiresAt ) {
			return false;
		}

		return $this->expiresAt->getTimestamp() - $skew <= $now->getTimestamp();
	}

	/**
	 * Refuse to be serialised.
	 *
	 * @return array<int, string>
	 *
	 * @throws LogicException Always.
	 */
	public function __sleep(): array {
		throw new LogicException( 'Connector credentials must not be serialised.' );
	}

	/**
	 * Keep the secrets out of var_dump and stack traces.
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array( 'values' => $this->isPresent() ? '[redacted]' : '[unset]' );
	}
}
