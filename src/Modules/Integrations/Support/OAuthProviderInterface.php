<?php
/**
 * OAuth-capable connector contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Support;

use Hiveclerk\Domain\Integration\AuthResult;
use Hiveclerk\Domain\Integration\ConnectorCredentials;

/**
 * The three things an authorisation-code flow needs from a connector.
 *
 * Kept separate from `CrmConnectorInterface` rather than folded into it,
 * because most connectors are not OAuth. A local plugin implementing
 * `authorizeUrl()` to return an empty string is a method that exists
 * only to be unimplemented, and the first person to call it in good
 * faith gets a redirect to nowhere.
 *
 * The service checks `instanceof` and offers the redirect flow only where
 * it is real. That is also what makes a third-party OAuth connector
 * registered through `hiveclerk/crm/connectors` work with no changes
 * here.
 */
interface OAuthProviderInterface {

	/**
	 * Where the operator is sent to approve the connection.
	 *
	 * @param ConnectorCredentials $credentials Application credentials.
	 * @param string               $redirectUri Our callback URL.
	 * @param string               $state       Opaque anti-forgery value.
	 * @return string
	 */
	public function authorizeUrl( ConnectorCredentials $credentials, string $redirectUri, string $state ): string;

	/**
	 * Trade an authorisation code for tokens.
	 *
	 * @param ConnectorCredentials $credentials Application credentials.
	 * @param string               $code        Authorisation code.
	 * @param string               $redirectUri The callback the code was issued against.
	 * @return AuthResult
	 */
	public function exchangeCode( ConnectorCredentials $credentials, string $code, string $redirectUri ): AuthResult;

	/**
	 * Mint a new access token from the stored refresh token.
	 *
	 * @param ConnectorCredentials $credentials Stored credentials.
	 * @return AuthResult
	 */
	public function refresh( ConnectorCredentials $credentials ): AuthResult;
}
