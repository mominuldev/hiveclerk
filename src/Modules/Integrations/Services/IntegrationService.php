<?php
/**
 * Integration management.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Services;

use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Integration\ConnectorCredentials;
use Hiveclerk\Domain\Integration\CrmConnectorInterface;
use Hiveclerk\Domain\Integration\FieldMap;
use Hiveclerk\Domain\Integration\Integration;
use Hiveclerk\Domain\Integration\IntegrationRepositoryInterface;
use Hiveclerk\Domain\Integration\IntegrationStatus;
use Hiveclerk\Domain\Integration\SyncLogEntry;
use Hiveclerk\Domain\Integration\SyncLogRepositoryInterface;
use Hiveclerk\Domain\Integration\SyncStatus;
use Hiveclerk\Domain\Integration\TestResult;

/**
 * Connecting, disconnecting, testing and mapping.
 *
 * ## Disconnecting does not delete anything
 *
 * `DELETE /admin/integrations/{provider}` clears the credentials and
 * marks the connection disconnected. It keeps the row, the field mapping
 * and the sync history, because all three are work the operator did and
 * none of them is a secret. Somebody rotating a HubSpot private app
 * disconnects and reconnects within a minute; deleting their mapping in
 * between would make that a twenty-minute job and lose the "214 contacts"
 * figure the card is built around.
 */
final class IntegrationService {

	public const CONNECTED       = 'integration.connected';
	public const DISCONNECTED    = 'integration.disconnected';
	public const MAPPING_UPDATED = 'integration.mapping.updated';
	public const TESTED          = 'integration.tested';

	/**
	 * Construct.
	 *
	 * @param IntegrationRepositoryInterface $integrations Connection storage.
	 * @param SyncLogRepositoryInterface     $log          Attempt storage.
	 * @param ConnectorRegistry              $connectors   Connector lookup.
	 * @param CredentialStore                $credentials  Secret storage.
	 * @param OAuthService                   $oauth        Redirect flow.
	 * @param AuditLogger                    $audit        Audit log.
	 * @param ClockInterface                 $clock        Clock.
	 */
	public function __construct(
		private readonly IntegrationRepositoryInterface $integrations,
		private readonly SyncLogRepositoryInterface $log,
		private readonly ConnectorRegistry $connectors,
		private readonly CredentialStore $credentials,
		private readonly OAuthService $oauth,
		private readonly AuditLogger $audit,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * The existing connection for a connector, or a fresh unsaved one.
	 *
	 * @param string $provider Connector identifier.
	 * @return Integration
	 */
	public function forProvider( string $provider ): Integration {
		$existing = $this->integrations->findByProvider( $provider );

		if ( null !== $existing ) {
			return $existing;
		}

		$connector = $this->connectors->get( $provider );

		return new Integration(
			id: null,
			provider: $provider,
			fieldMap: null === $connector ? new FieldMap() : $connector->defaultMap(),
			syncConfig: array( 'trigger' => 'qualified' ),
		);
	}

	/**
	 * Store credentials and mark a connection live.
	 *
	 * For an OAuth connector this stores only the application credentials
	 * and leaves the status disconnected — there is nothing to connect to
	 * until the operator has come back through the callback, and a card
	 * showing "connected" before that would be a lie the first push
	 * corrects.
	 *
	 * @param string                $provider  Connector identifier.
	 * @param array<string, string> $settings  Submitted credential fields.
	 * @return array{integration: Integration, redirect: string|null, error: string|null}
	 */
	public function connect( string $provider, array $settings ): array {
		$connector = $this->connectors->get( $provider );

		if ( null === $connector ) {
			return array(
				'integration' => $this->forProvider( $provider ),
				'redirect'    => null,
				'error'       => __( 'That integration is not installed.', 'hiveclerk' ),
			);
		}

		$integration = $this->forProvider( $provider );

		if ( null === $integration->id ) {
			$integration = $this->integrations->save( $integration );
		}

		$existing = $this->credentials->read( $integration );

		// Merged over what is stored, so a form that came back with a
		// masked secret field does not blank the real one. A submitted
		// empty string means "leave it alone", which is what the UI shows.
		$submitted = array_filter(
			$settings,
			static fn ( string $value ): bool => '' !== trim( $value )
		);

		$result = $connector->authenticate( $existing->with( $submitted ) );

		if ( ! $result->ok || null === $result->credentials ) {
			return array(
				'integration' => $integration,
				'redirect'    => null,
				'error'       => '' === $result->message
					? __( 'Those credentials were refused.', 'hiveclerk' )
					: $result->message,
			);
		}

		$this->credentials->write( $integration, $result->credentials );

		if ( $this->oauth->supports( $connector ) ) {
			$this->integrations->save( $integration );

			return array(
				'integration' => $integration,
				'redirect'    => $this->oauth->begin( $integration, $connector ),
				'error'       => null,
			);
		}

		$integration->status     = IntegrationStatus::Connected;
		$integration->name       = $result->account ?? $integration->name;
		$integration->lastError  = null;
		$integration->errorCount = 0;

		if ( $integration->fieldMap->isEmpty() ) {
			$integration->fieldMap = $connector->defaultMap();
		}

		$integration = $this->integrations->save( $integration );

		$this->audit->record(
			self::CONNECTED,
			array( 'provider' => $provider ),
			'integration',
			$integration->id
		);

		return array(
			'integration' => $integration,
			'redirect'    => null,
			'error'       => null,
		);
	}

	/**
	 * Clear the credentials, keeping the mapping and the history.
	 *
	 * @param Integration $integration Connection.
	 * @return Integration
	 */
	public function disconnect( Integration $integration ): Integration {
		$this->credentials->forget( $integration );

		$integration->status     = IntegrationStatus::Disconnected;
		$integration->name       = null;
		$integration->lastError  = null;
		$integration->errorCount = 0;

		$integration = $this->integrations->save( $integration );

		$this->audit->record(
			self::DISCONNECTED,
			array( 'provider' => $integration->provider ),
			'integration',
			$integration->id
		);

		return $integration;
	}

	/**
	 * Run a live check and record it.
	 *
	 * The test is logged like any other attempt. "It worked when I pressed
	 * the button an hour ago" is a thing an operator says while looking at
	 * a screen that cannot show it, and a row in the sync log can.
	 *
	 * @param Integration $integration Connection.
	 * @return TestResult
	 */
	public function test( Integration $integration ): TestResult {
		$connector = $this->connectors->get( $integration->provider );

		if ( null === $connector ) {
			return TestResult::fail( __( 'That integration is not installed.', 'hiveclerk' ) );
		}

		$credentials = $this->oauth->fresh( $integration, $connector );

		$result = null === $credentials
			? TestResult::fail( __( 'The stored credentials could not be refreshed. Reconnect this integration.', 'hiveclerk' ) )
			: $connector->test( $credentials );

		if ( null !== $integration->id ) {
			$this->log->append(
				new SyncLogEntry(
					id: null,
					integrationId: $integration->id,
					operation: SyncLogEntry::OP_TEST,
					status: $result->ok ? SyncStatus::Success : SyncStatus::Failed,
					error: $result->ok ? null : $result->message,
					createdAt: $this->clock->now(),
				)
			);
		}

		if ( $result->ok ) {
			$integration->status     = IntegrationStatus::Connected;
			$integration->lastError  = null;
			$integration->errorCount = 0;
			$integration->name       = $result->account ?? $integration->name;
		} elseif ( null === $credentials ) {
			$integration->status = IntegrationStatus::Expired;
		}

		$this->integrations->save( $integration );

		$this->audit->record(
			self::TESTED,
			array(
				'provider' => $integration->provider,
				'ok'       => $result->ok,
			),
			'integration',
			$integration->id
		);

		return $result;
	}

	/**
	 * Replace the field mapping and the sync rules.
	 *
	 * @param Integration          $integration Connection.
	 * @param FieldMap             $map         New mapping.
	 * @param array<string, mixed> $syncConfig  New sync rules.
	 * @return Integration
	 */
	public function saveMapping( Integration $integration, FieldMap $map, array $syncConfig ): Integration {
		$connector = $this->connectors->get( $integration->provider );

		// The email row is locked in the UI, and locked here too. A client
		// that skips the form and PUTs a mapping without it would produce a
		// connection whose every push fails on a contact with no identity.
		if ( null !== $connector ) {
			$default = $connector->defaultMap()->target( 'email' );

			if ( null !== $default ) {
				$map = $map->with( 'email', $default );
			}
		}

		$integration->fieldMap   = $map;
		$integration->syncConfig = $syncConfig;

		$integration = $this->integrations->save( $integration );

		$this->audit->record(
			self::MAPPING_UPDATED,
			array(
				'provider' => $integration->provider,
				'fields'   => count( $map->toArray() ),
				'trigger'  => $integration->trigger()->value,
			),
			'integration',
			$integration->id
		);

		return $integration;
	}

	/**
	 * Whether a connection has anything stored to authenticate with.
	 *
	 * @param Integration $integration Connection.
	 * @return bool
	 */
	public function hasCredentials( Integration $integration ): bool {
		return $this->credentials->read( $integration )->isPresent();
	}

	/**
	 * Fields the mapping screen can point at, live where possible.
	 *
	 * @param Integration $integration Connection.
	 * @return array<int, array<string, mixed>>
	 */
	public function targetFields( Integration $integration ): array {
		$connector = $this->connectors->get( $integration->provider );

		if ( null === $connector ) {
			return array();
		}

		$credentials = $this->credentials->read( $integration );

		return array_map(
			static fn ( $field ): array => $field->toArray(),
			$connector->availableFields( $credentials )
		);
	}

	/**
	 * Credentials for a connector that has no stored connection yet.
	 *
	 * @return ConnectorCredentials
	 */
	public function emptyCredentials(): ConnectorCredentials {
		return ConnectorCredentials::none();
	}

	/**
	 * The connector behind a connection, when it is still installed.
	 *
	 * @param Integration $integration Connection.
	 * @return CrmConnectorInterface|null
	 */
	public function connector( Integration $integration ): ?CrmConnectorInterface {
		return $this->connectors->get( $integration->provider );
	}
}
