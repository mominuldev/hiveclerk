<?php
/**
 * Authentication outcome.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

/**
 * What a connector made of the credentials it was handed.
 *
 * Carries credentials back rather than only a boolean because the OAuth
 * exchange produces them: the connector receives an authorisation code
 * and returns the access and refresh tokens the store then encrypts. A
 * local connector returns whatever it was given, unchanged.
 */
final readonly class AuthResult {

	/**
	 * Construct.
	 *
	 * @param bool                      $ok          Whether authentication succeeded.
	 * @param ConnectorCredentials|null $credentials What to store, when it did.
	 * @param string                    $message     Operator-facing reason when it did not.
	 * @param string|null               $account     Which account was reached.
	 */
	public function __construct(
		public bool $ok,
		public ?ConnectorCredentials $credentials = null,
		public string $message = '',
		public ?string $account = null
	) {
	}

	/**
	 * Authenticated.
	 *
	 * @param ConnectorCredentials $credentials What to store.
	 * @param string|null          $account     Account name.
	 * @return self
	 */
	public static function ok( ConnectorCredentials $credentials, ?string $account = null ): self {
		return new self( true, $credentials, '', $account );
	}

	/**
	 * Not authenticated.
	 *
	 * @param string $message Reason.
	 * @return self
	 */
	public static function failed( string $message ): self {
		return new self( false, null, $message );
	}
}
