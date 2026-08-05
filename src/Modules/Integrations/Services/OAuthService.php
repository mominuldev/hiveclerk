<?php
/**
 * OAuth 2.0 authorisation-code flow.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Services;

use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Integration\ConnectorCredentials;
use Hiveclerk\Domain\Integration\CrmConnectorInterface;
use Hiveclerk\Domain\Integration\Integration;
use Hiveclerk\Domain\Integration\IntegrationRepositoryInterface;
use Hiveclerk\Domain\Integration\IntegrationStatus;
use Hiveclerk\Modules\Integrations\Support\OAuthProviderInterface;

/**
 * Redirects out, verifies the way back, and keeps tokens alive.
 *
 * ## The state parameter is doing real work
 *
 * Without it, the callback accepts an authorisation code from anywhere.
 * An attacker who gets an administrator to load a crafted callback URL
 * binds *their* CRM account to the customer's site, and every lead the
 * customer captures from then on is delivered to the attacker's HubSpot
 * portal. The state is random, short-lived, stored server-side against
 * the provider it was issued for, and compared in constant time.
 *
 * A nonce would not do this job. Nonces are bound to the WordPress user
 * and survive twelve hours; the value here has to survive a round trip
 * through a third party and then never be accepted again.
 *
 * ## Why refresh happens at use rather than on a schedule
 *
 * A cron job that refreshes tokens is a job that stops running silently
 * on a site with no traffic and leaves every token dead. Refreshing on
 * the push path means the token is renewed exactly when it is needed and
 * never when it is not, and a site that syncs nothing spends nothing.
 */
final class OAuthService {

	/**
	 * How long an authorisation may take, in seconds.
	 *
	 * Ten minutes. Long enough to log into a CRM, pick the right account
	 * and approve; short enough that a state value left in a browser
	 * history is worthless by the time anybody finds it.
	 */
	private const STATE_TTL = 600;

	/**
	 * Transient prefix for pending authorisations.
	 */
	private const STATE_PREFIX = 'hiveclerk_oauth_';

	/**
	 * Construct.
	 *
	 * @param IntegrationRepositoryInterface $integrations Connection storage.
	 * @param CredentialStore                $credentials  Secret storage.
	 * @param ClockInterface                 $clock        Clock.
	 */
	public function __construct(
		private readonly IntegrationRepositoryInterface $integrations,
		private readonly CredentialStore $credentials,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Whether a connector uses the redirect flow.
	 *
	 * @param CrmConnectorInterface $connector Connector.
	 * @return bool
	 */
	public function supports( CrmConnectorInterface $connector ): bool {
		return $connector instanceof OAuthProviderInterface;
	}

	/**
	 * Begin an authorisation and return where to send the operator.
	 *
	 * @param Integration           $integration Connection.
	 * @param CrmConnectorInterface $connector   Connector.
	 * @return string|null Null when the connector does not use OAuth.
	 */
	public function begin( Integration $integration, CrmConnectorInterface $connector ): ?string {
		if ( ! $connector instanceof OAuthProviderInterface ) {
			return null;
		}

		$state = bin2hex( random_bytes( 16 ) );

		set_transient(
			self::STATE_PREFIX . $state,
			array(
				'provider'       => $integration->provider,
				'integration_id' => $integration->id,
				'user'           => get_current_user_id(),
			),
			self::STATE_TTL
		);

		return $connector->authorizeUrl(
			$this->credentials->read( $integration ),
			$this->redirectUri( $integration->provider ),
			$state
		);
	}

	/**
	 * Verify a callback and store what it produced.
	 *
	 * @param CrmConnectorInterface $connector Connector.
	 * @param string                $code      Authorisation code.
	 * @param string                $state     State value from the callback.
	 * @return string|null An error message, or null on success.
	 */
	public function complete( CrmConnectorInterface $connector, string $code, string $state ): ?string {
		if ( ! $connector instanceof OAuthProviderInterface ) {
			return __( 'That integration does not use OAuth.', 'hiveclerk' );
		}

		$pending = get_transient( self::STATE_PREFIX . $state );

		// Deleted before anything else happens. A state that survives its
		// own use is a code that can be replayed.
		delete_transient( self::STATE_PREFIX . $state );

		if ( ! is_array( $pending ) ) {
			return __( 'That authorisation has expired or was not started here. Try connecting again.', 'hiveclerk' );
		}

		$provider = isset( $pending['provider'] ) && is_string( $pending['provider'] ) ? $pending['provider'] : '';

		if ( ! hash_equals( $connector->id(), $provider ) ) {
			return __( 'That authorisation was for a different integration.', 'hiveclerk' );
		}

		$integration = $this->integrations->findByProvider( $connector->id() );

		if ( null === $integration ) {
			return __( 'That integration is no longer configured.', 'hiveclerk' );
		}

		$result = $connector->exchangeCode(
			$this->credentials->read( $integration ),
			$code,
			$this->redirectUri( $connector->id() )
		);

		if ( ! $result->ok || null === $result->credentials ) {
			return '' === $result->message
				? __( 'The provider refused the authorisation.', 'hiveclerk' )
				: $result->message;
		}

		$this->credentials->write( $integration, $result->credentials );

		$integration->status     = IntegrationStatus::Connected;
		$integration->lastError  = null;
		$integration->errorCount = 0;

		if ( null !== $result->account ) {
			$integration->name = $result->account;
		}

		$this->integrations->save( $integration );

		return null;
	}

	/**
	 * Credentials that are usable right now, refreshing if needed.
	 *
	 * Returns null when the connection cannot be revived — which is the
	 * signal to mark it expired and tell the operator to reconnect,
	 * rather than to keep retrying against a dead token for fifteen
	 * hours.
	 *
	 * @param Integration           $integration Connection.
	 * @param CrmConnectorInterface $connector   Connector.
	 * @return ConnectorCredentials|null
	 */
	public function fresh( Integration $integration, CrmConnectorInterface $connector ): ?ConnectorCredentials {
		$credentials = $this->credentials->read( $integration );

		if ( ! $connector instanceof OAuthProviderInterface ) {
			return $credentials;
		}

		if ( ! $credentials->isExpired( $this->clock->now() ) ) {
			return $credentials;
		}

		$result = $connector->refresh( $credentials );

		if ( ! $result->ok || null === $result->credentials ) {
			return null;
		}

		$this->credentials->write( $integration, $result->credentials );

		return $result->credentials;
	}

	/**
	 * The callback URL for one connector.
	 *
	 * Built from `rest_url()` so it matches whatever permalink structure
	 * and site URL the customer has — a hard-coded path breaks on every
	 * install running plain permalinks, which is the configuration the
	 * cheapest hosts still ship.
	 *
	 * @param string $provider Connector identifier.
	 * @return string
	 */
	public function redirectUri( string $provider ): string {
		return rest_url( 'hiveclerk/v1/admin/integrations/' . $provider . '/callback' );
	}
}
