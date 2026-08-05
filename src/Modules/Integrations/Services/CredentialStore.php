<?php
/**
 * Connector credential storage.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Services;

use Hiveclerk\Core\Support\Encryptor;
use Hiveclerk\Domain\Integration\ConnectorCredentials;
use Hiveclerk\Domain\Integration\Integration;
use Hiveclerk\Domain\Integration\IntegrationRepositoryInterface;

/**
 * The only thing that turns a stored token back into a usable one.
 *
 * Same shape as `Ai\KeyResolver`, for the same reasons and with one
 * difference: a CRM token has to be decrypted on a *write* path, because
 * pushing a contact needs the real value. There is no way around that, so
 * the decryption is confined to this class and everything else takes a
 * `ConnectorCredentials` that refuses to be serialised.
 *
 * The credentials column holds a JSON object rather than a single string
 * because OAuth needs three values and a webhook needs two. It is
 * encrypted as one blob: encrypting per key would leak which fields are
 * set through the ciphertext length, and it would multiply the number of
 * places a decryption can go wrong by the number of fields.
 */
final class CredentialStore {

	/**
	 * Construct.
	 *
	 * @param IntegrationRepositoryInterface $integrations Storage.
	 * @param Encryptor                      $encryptor    Secret encryption.
	 */
	public function __construct(
		private readonly IntegrationRepositoryInterface $integrations,
		private readonly Encryptor $encryptor
	) {
	}

	/**
	 * Read the credentials for a connection.
	 *
	 * Returns empty credentials rather than throwing when the ciphertext
	 * cannot be read. An unreadable secret means the site's salts changed
	 * or the row was tampered with; either way the connector reports "not
	 * connected" and the operator reconnects, which is a better outcome
	 * than a fatal on the Integrations screen.
	 *
	 * @param Integration $integration Connection.
	 * @return ConnectorCredentials
	 */
	public function read( Integration $integration ): ConnectorCredentials {
		if ( null === $integration->id ) {
			return ConnectorCredentials::none();
		}

		$ciphertext = $this->integrations->secret( $integration->id );

		if ( null === $ciphertext ) {
			return ConnectorCredentials::none();
		}

		$plaintext = $this->encryptor->decrypt( $ciphertext );

		if ( null === $plaintext ) {
			return ConnectorCredentials::none();
		}

		$decoded = json_decode( $plaintext, true );

		if ( ! is_array( $decoded ) ) {
			return ConnectorCredentials::none();
		}

		$values = array();

		foreach ( $decoded as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) ) {
				$values[ $key ] = $value;
			}
		}

		return new ConnectorCredentials( $values, $integration->tokenExpiresAt );
	}

	/**
	 * Write the credentials for a connection.
	 *
	 * @param Integration          $integration Connection.
	 * @param ConnectorCredentials $credentials What to store.
	 * @return void
	 */
	public function write( Integration $integration, ConnectorCredentials $credentials ): void {
		if ( null === $integration->id ) {
			return;
		}

		$encoded = wp_json_encode( $credentials->toArray() );

		$this->integrations->storeSecret(
			$integration->id,
			false === $encoded ? null : $this->encryptor->encrypt( $encoded ),
			$credentials->expiresAt
		);

		$integration->tokenExpiresAt = $credentials->expiresAt;
	}

	/**
	 * Forget the credentials for a connection.
	 *
	 * @param Integration $integration Connection.
	 * @return void
	 */
	public function forget( Integration $integration ): void {
		if ( null !== $integration->id ) {
			$this->integrations->storeSecret( $integration->id, null, null );

			$integration->tokenExpiresAt = null;
		}
	}
}
