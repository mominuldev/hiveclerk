<?php
/**
 * Outbound webhook connector.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Connectors;

use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Integration\AuthResult;
use Hiveclerk\Domain\Integration\ConnectorCredentials;
use Hiveclerk\Domain\Integration\ConnectorDescriptor;
use Hiveclerk\Domain\Integration\ConnectorField;
use Hiveclerk\Domain\Integration\ConnectorSetting;
use Hiveclerk\Domain\Integration\FieldMap;
use Hiveclerk\Domain\Integration\SyncResult;
use Hiveclerk\Domain\Integration\TestResult;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Modules\Integrations\Support\ConnectorHttp;
use Hiveclerk\Modules\Integrations\Support\WebhookSigner;

/**
 * The universal fallback (FR-CRM-09).
 *
 * Every CRM this product will never write a connector for is reachable
 * through Zapier, Make, n8n or twenty lines of PHP on the customer's own
 * server. That makes this the highest-leverage integration in the sprint
 * and the one most likely to be pointed somewhere dangerous — a URL typed
 * into a settings field is the same server-side request forgery primitive
 * as a URL typed into a crawl form, so it goes through the same guard and
 * only `https://` is accepted.
 *
 * The mapping screen still applies. A receiver gets the fields the
 * operator chose under the names the operator chose, rather than an
 * internal shape that would become a compatibility promise the moment
 * somebody built against it.
 */
final class WebhookConnector extends AbstractConnector {

	/**
	 * Construct.
	 *
	 * @param ConnectorHttp  $http   Outbound HTTP.
	 * @param WebhookSigner  $signer Signature builder.
	 * @param ClockInterface $clock  Clock.
	 */
	public function __construct(
		private readonly ConnectorHttp $http,
		private readonly WebhookSigner $signer,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * What the grid draws.
	 *
	 * @return ConnectorDescriptor
	 */
	public function descriptor(): ConnectorDescriptor {
		return new ConnectorDescriptor(
			id: 'webhook',
			name: __( 'Webhook', 'hiveclerk' ),
			kind: ConnectorDescriptor::KIND_NOTIFICATION,
			auth: ConnectorDescriptor::AUTH_URL,
			summary: __( 'Post every qualified lead to a URL you control, signed so you can prove it came from this site.', 'hiveclerk' ),
			isPro: false,
			settings: array(
				new ConnectorSetting(
					key: 'url',
					label: __( 'Endpoint URL', 'hiveclerk' ),
					type: 'url',
					secret: false,
					required: true,
					help: __( 'Must be https. Private and link-local addresses are refused.', 'hiveclerk' ),
					placeholder: 'https://example.com/hooks/hiveclerk'
				),
				new ConnectorSetting(
					key: 'secret',
					label: __( 'Signing secret', 'hiveclerk' ),
					type: 'password',
					secret: true,
					required: false,
					help: __( 'Generated for you if you leave this blank. Verify X-HVC-Signature against it.', 'hiveclerk' )
				),
			),
			docsUrl: 'https://hiveclerk.com/docs/integrations/webhooks'
		);
	}

	/**
	 * Accept a URL, minting a secret if none was given.
	 *
	 * @param ConnectorCredentials $credentials Submitted settings.
	 * @return AuthResult
	 */
	public function authenticate( ConnectorCredentials $credentials ): AuthResult {
		$url = $credentials->get( 'url' );

		if ( ! str_starts_with( $url, 'https://' ) ) {
			return AuthResult::failed( __( 'The endpoint must be an https URL.', 'hiveclerk' ) );
		}

		$secret = $credentials->get( 'secret' );

		if ( '' === trim( $secret ) ) {
			$credentials = $credentials->with( array( 'secret' => $this->signer->generateSecret() ) );
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		return AuthResult::ok( $credentials, is_string( $host ) ? $host : null );
	}

	/**
	 * Send a ping the receiver can verify against.
	 *
	 * @param ConnectorCredentials $credentials Stored settings.
	 * @return TestResult
	 */
	public function test( ConnectorCredentials $credentials ): TestResult {
		$response = $this->deliver(
			$credentials,
			'test',
			array(
				'event'     => 'test',
				'site'      => home_url(),
				'message'   => 'Hiveclerk is checking this endpoint.',
				'timestamp' => $this->clock->now()->format( 'c' ),
			)
		);

		if ( ! $response->ok() ) {
			return TestResult::fail( $response->errorMessage() );
		}

		$host = wp_parse_url( $credentials->get( 'url' ), PHP_URL_HOST );

		return TestResult::pass(
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The endpoint answered %d.', 'hiveclerk' ),
				$response->status
			),
			is_string( $host ) ? $host : null
		);
	}

	/**
	 * Post the mapped lead.
	 *
	 * @param Lead                 $lead        The lead.
	 * @param FieldMap             $map         Field mapping.
	 * @param ConnectorCredentials $credentials Stored settings.
	 * @param array<string, mixed> $payload     Mapped values.
	 * @return SyncResult
	 */
	public function pushContact(
		Lead $lead,
		FieldMap $map,
		ConnectorCredentials $credentials,
		array $payload
	): SyncResult {
		unset( $map );

		$response = $this->deliver(
			$credentials,
			'lead.synced',
			array(
				'event' => 'lead.synced',
				'lead'  => array_merge( array( 'id' => $lead->uuid->value ), $payload ),
				'site'  => home_url(),
			)
		);

		if ( $response->ok() ) {
			return SyncResult::success(
				$lead->uuid->value,
				array( 'fields' => array_keys( $payload ) ),
				$response->status
			);
		}

		return $response->isRetryable()
			? SyncResult::transient( $response->errorMessage(), $response->status )
			: SyncResult::permanent( $response->errorMessage(), $response->status );
	}

	/**
	 * Every lead field, since the receiver decides what it wants.
	 *
	 * A webhook has no schema to fetch. The mapping screen offers the
	 * source names themselves as targets, so a receiver reads
	 * `first_name` unless the operator renamed it.
	 *
	 * @param ConnectorCredentials $credentials Ignored.
	 * @return array<int, ConnectorField>
	 */
	public function availableFields( ConnectorCredentials $credentials ): array {
		unset( $credentials );

		$fields = array( new ConnectorField( 'email', __( 'email', 'hiveclerk' ), 'email', false, true, true ) );

		foreach ( FieldMap::SOURCES as $source ) {
			if ( 'email' === $source ) {
				continue;
			}

			$fields[] = new ConnectorField( $source, $source );
		}

		return $fields;
	}

	/**
	 * Everything, under its own name.
	 *
	 * @return FieldMap
	 */
	public function defaultMap(): FieldMap {
		$pairs = array();

		foreach ( FieldMap::SOURCES as $source ) {
			if ( 'transcript' === $source ) {
				continue;
			}

			$pairs[ $source ] = $source;
		}

		return new FieldMap( $pairs );
	}

	/**
	 * Post a signed body.
	 *
	 * @param ConnectorCredentials $credentials Stored settings.
	 * @param string               $event       Event name.
	 * @param array<string, mixed> $payload     Body.
	 * @return \Hiveclerk\Modules\Integrations\Support\ConnectorResponse
	 */
	private function deliver(
		ConnectorCredentials $credentials,
		string $event,
		array $payload
	): \Hiveclerk\Modules\Integrations\Support\ConnectorResponse {
		$body      = (string) wp_json_encode( $payload );
		$timestamp = $this->clock->now()->getTimestamp();

		return $this->http->postRaw(
			$credentials->get( 'url' ),
			$body,
			$this->signer->headers( $body, $credentials->get( 'secret' ), $timestamp, $event ),
			false
		);
	}
}
