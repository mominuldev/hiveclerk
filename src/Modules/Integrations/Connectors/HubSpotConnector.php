<?php
/**
 * HubSpot connector.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Connectors;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Integration\AuthResult;
use Hiveclerk\Domain\Integration\ConnectorCredentials;
use Hiveclerk\Domain\Integration\ConnectorDescriptor;
use Hiveclerk\Domain\Integration\ConnectorField;
use Hiveclerk\Domain\Integration\ConnectorSetting;
use Hiveclerk\Domain\Integration\FieldMap;
use Hiveclerk\Domain\Integration\SyncResult;
use Hiveclerk\Domain\Integration\TestResult;
use Hiveclerk\Domain\Lead\Activity;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Modules\Integrations\Support\ConnectorHttp;
use Hiveclerk\Modules\Integrations\Support\ConnectorResponse;
use Hiveclerk\Modules\Integrations\Support\OAuthProviderInterface;

/**
 * HubSpot over OAuth 2.0 (FR-CRM-04).
 *
 * ## Why the contact is upserted by email rather than created
 *
 * HubSpot answers a create for an address it already holds with a 409
 * carrying the existing id. Treating that as an error would mean the
 * second push of the same lead — which happens the moment a score moves
 * — fails permanently and lands red in the sync log. The 409 is read,
 * the id is pulled out of it, and the call becomes a PATCH. The result
 * for the operator is that pushing twice is safe, which is the only
 * behaviour a retry policy can be built on.
 *
 * ## Why the app credentials are the customer's
 *
 * A shared Hiveclerk HubSpot app would put every customer's contact data
 * behind one client secret held by us — a single credential whose theft
 * is a breach at every site running the plugin. The customer creates
 * their own private app; the client id and secret stay encrypted on their
 * server and the tokens they mint reach nothing else.
 */
final class HubSpotConnector extends AbstractConnector implements OAuthProviderInterface {

	private const AUTHORIZE_URL = 'https://app.hubspot.com/oauth/authorize';
	private const TOKEN_URL     = 'https://api.hubapi.com/oauth/v1/token';
	private const API_BASE      = 'https://api.hubapi.com';

	/**
	 * Scopes requested.
	 *
	 * The narrowest set that lets the connector do what it claims: write
	 * a contact, read the property list the mapping screen renders, and
	 * attach a note. No deal, ticket or file access is asked for, so a
	 * stolen token cannot reach a customer's pipeline.
	 */
	private const SCOPES = 'crm.objects.contacts.read crm.objects.contacts.write crm.schemas.contacts.read';

	/**
	 * Construct.
	 *
	 * @param ConnectorHttp  $http  Outbound HTTP.
	 * @param ClockInterface $clock Clock.
	 */
	public function __construct(
		private readonly ConnectorHttp $http,
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
			id: 'hubspot',
			name: 'HubSpot',
			kind: ConnectorDescriptor::KIND_CRM,
			auth: ConnectorDescriptor::AUTH_OAUTH,
			summary: __( 'Sync qualified leads into HubSpot contacts. Uses your own private app, so the credentials never leave this server.', 'hiveclerk' ),
			isPro: true,
			settings: array(
				new ConnectorSetting(
					key: 'client_id',
					label: __( 'Client ID', 'hiveclerk' ),
					type: 'text',
					secret: false,
					required: true,
					help: __( 'From your HubSpot private app, under Auth.', 'hiveclerk' )
				),
				new ConnectorSetting(
					key: 'client_secret',
					label: __( 'Client secret', 'hiveclerk' ),
					type: 'password',
					secret: true,
					required: true,
					help: __( 'Stored encrypted. It is never shown again after you save it.', 'hiveclerk' )
				),
			),
			docsUrl: 'https://hiveclerk.com/docs/integrations/hubspot'
		);
	}

	/**
	 * Where the operator is sent to approve the connection.
	 *
	 * @param ConnectorCredentials $credentials Client id and secret.
	 * @param string               $redirectUri Our callback.
	 * @param string               $state       Opaque anti-forgery value.
	 * @return string
	 */
	public function authorizeUrl( ConnectorCredentials $credentials, string $redirectUri, string $state ): string {
		return add_query_arg(
			array(
				'client_id'    => rawurlencode( $credentials->get( 'client_id' ) ),
				'redirect_uri' => rawurlencode( $redirectUri ),
				'scope'        => rawurlencode( self::SCOPES ),
				'state'        => rawurlencode( $state ),
			),
			self::AUTHORIZE_URL
		);
	}

	/**
	 * Trade an authorisation code for tokens.
	 *
	 * @param ConnectorCredentials $credentials Client id and secret.
	 * @param string               $code        Authorisation code.
	 * @param string               $redirectUri The same callback the code was issued for.
	 * @return AuthResult
	 */
	public function exchangeCode(
		ConnectorCredentials $credentials,
		string $code,
		string $redirectUri
	): AuthResult {
		return $this->token(
			$credentials,
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => $credentials->get( 'client_id' ),
				'client_secret' => $credentials->get( 'client_secret' ),
				'redirect_uri'  => $redirectUri,
				'code'          => $code,
			)
		);
	}

	/**
	 * Mint a new access token from the refresh token.
	 *
	 * @param ConnectorCredentials $credentials Stored credentials.
	 * @return AuthResult
	 */
	public function refresh( ConnectorCredentials $credentials ): AuthResult {
		if ( ! $credentials->has( 'refresh_token' ) ) {
			return AuthResult::failed(
				__( 'There is no refresh token stored. Reconnect HubSpot.', 'hiveclerk' )
			);
		}

		return $this->token(
			$credentials,
			array(
				'grant_type'    => 'refresh_token',
				'client_id'     => $credentials->get( 'client_id' ),
				'client_secret' => $credentials->get( 'client_secret' ),
				'refresh_token' => $credentials->get( 'refresh_token' ),
			)
		);
	}

	/**
	 * Accept the app credentials before the redirect.
	 *
	 * Nothing is verified here — there is nothing to verify against until
	 * the operator has approved the app. Verification happens on the way
	 * back, in exchangeCode().
	 *
	 * @param ConnectorCredentials $credentials Client id and secret.
	 * @return AuthResult
	 */
	public function authenticate( ConnectorCredentials $credentials ): AuthResult {
		if ( ! $credentials->has( 'client_id' ) || ! $credentials->has( 'client_secret' ) ) {
			return AuthResult::failed(
				__( 'HubSpot needs both a client ID and a client secret from your private app.', 'hiveclerk' )
			);
		}

		return AuthResult::ok( $credentials );
	}

	/**
	 * Ask HubSpot who this token belongs to.
	 *
	 * @param ConnectorCredentials $credentials Stored credentials.
	 * @return TestResult
	 */
	public function test( ConnectorCredentials $credentials ): TestResult {
		if ( ! $credentials->has( 'access_token' ) ) {
			return TestResult::fail( __( 'HubSpot is not connected yet.', 'hiveclerk' ) );
		}

		$response = $this->http->get(
			self::API_BASE . '/oauth/v1/access-tokens/' . rawurlencode( $credentials->get( 'access_token' ) )
		);

		if ( ! $response->ok() ) {
			return TestResult::fail( $response->errorMessage() );
		}

		$account = $response->string( 'hub_domain' );

		if ( '' === $account ) {
			$account = $response->string( 'hub_id' );
		}

		return TestResult::pass(
			__( 'HubSpot answered.', 'hiveclerk' ),
			'' === $account ? null : $account
		);
	}

	/**
	 * Create or update a contact.
	 *
	 * @param Lead                 $lead        The lead.
	 * @param FieldMap             $map         Field mapping.
	 * @param ConnectorCredentials $credentials Stored credentials.
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

		if ( ! $credentials->has( 'access_token' ) ) {
			return SyncResult::permanent( __( 'HubSpot is not connected.', 'hiveclerk' ) );
		}

		if ( null === $lead->email ) {
			return SyncResult::permanent( __( 'HubSpot identifies contacts by email and this lead has none.', 'hiveclerk' ) );
		}

		$properties          = $this->stringify( $payload );
		$properties['email'] = $lead->email;

		$response = $this->http->postJson(
			self::API_BASE . '/crm/v3/objects/contacts',
			array( 'properties' => $properties ),
			$this->headers( $credentials )
		);

		if ( $response->ok() ) {
			return SyncResult::success(
				$response->string( 'id' ),
				array( 'fields' => array_keys( $properties ) ),
				$response->status
			);
		}

		// 409 means the address is already a contact, and the message
		// carries its id. That is an update, not a failure.
		if ( 409 === $response->status ) {
			$existing = self::conflictId( $response );

			if ( null !== $existing ) {
				return $this->update( $existing, $properties, $credentials );
			}
		}

		return $this->failure( $response );
	}

	/**
	 * Attach a note to the contact.
	 *
	 * @param Lead                 $lead        The lead.
	 * @param Activity             $activity    What happened.
	 * @param ConnectorCredentials $credentials Stored credentials.
	 * @param string|null          $externalId  Contact id.
	 * @return SyncResult
	 */
	public function pushActivity(
		Lead $lead,
		Activity $activity,
		ConnectorCredentials $credentials,
		?string $externalId
	): SyncResult {
		unset( $lead );

		if ( null === $externalId || ! $credentials->has( 'access_token' ) ) {
			return SyncResult::success();
		}

		$body = trim( $activity->title . "\n\n" . (string) $activity->body );

		$response = $this->http->postJson(
			self::API_BASE . '/crm/v3/objects/notes',
			array(
				'properties'   => array(
					'hs_note_body' => $body,
					'hs_timestamp' => $this->clock->now()->format( 'c' ),
				),
				'associations' => array(
					array(
						'to'    => array( 'id' => $externalId ),
						'types' => array(
							array(
								// 202 is HubSpot's own identifier for
								// note-to-contact. It is defined by them, not
								// chosen by us, and there is no endpoint that
								// returns it by name.
								'associationCategory' => 'HUBSPOT_DEFINED',
								'associationTypeId'   => 202,
							),
						),
					),
				),
			),
			$this->headers( $credentials )
		);

		return $response->ok()
			? SyncResult::success( $externalId, array(), $response->status )
			: $this->failure( $response );
	}

	/**
	 * Contact properties, fetched live.
	 *
	 * @param ConnectorCredentials $credentials Stored credentials.
	 * @return array<int, ConnectorField>
	 */
	public function availableFields( ConnectorCredentials $credentials ): array {
		$native = array(
			new ConnectorField( 'email', __( 'Email', 'hiveclerk' ), 'email', false, true, true ),
			new ConnectorField( 'firstname', __( 'First name', 'hiveclerk' ) ),
			new ConnectorField( 'lastname', __( 'Last name', 'hiveclerk' ) ),
			new ConnectorField( 'phone', __( 'Phone number', 'hiveclerk' ), 'phone' ),
			new ConnectorField( 'company', __( 'Company name', 'hiveclerk' ) ),
			new ConnectorField( 'jobtitle', __( 'Job title', 'hiveclerk' ) ),
			new ConnectorField( 'website', __( 'Website URL', 'hiveclerk' ), 'url' ),
		);

		if ( ! $credentials->has( 'access_token' ) ) {
			return $native;
		}

		$response = $this->http->get(
			self::API_BASE . '/crm/v3/properties/contacts',
			$this->headers( $credentials )
		);

		if ( ! $response->ok() ) {
			// The mapping screen still renders. An empty field list reads
			// as a broken product; the native set is at least true.
			return $native;
		}

		$results = $response->get( 'results' );

		if ( ! is_array( $results ) ) {
			return $native;
		}

		$fields = array();

		foreach ( $results as $property ) {
			if ( ! is_array( $property ) ) {
				continue;
			}

			$name = isset( $property['name'] ) && is_string( $property['name'] ) ? $property['name'] : '';

			// Read-only and calculated properties are rejected on write,
			// and offering one produces a mapping that fails every push.
			$readOnly   = (bool) ( $property['modificationMetadata']['readOnlyValue'] ?? false );
			$calculated = (bool) ( $property['calculated'] ?? false );

			if ( '' === $name || $readOnly || $calculated ) {
				continue;
			}

			$fields[] = new ConnectorField(
				$name,
				isset( $property['label'] ) && is_string( $property['label'] ) ? $property['label'] : $name,
				isset( $property['type'] ) && is_string( $property['type'] ) ? $property['type'] : 'text',
				! (bool) ( $property['hubspotDefined'] ?? false ),
				'email' === $name,
				'email' === $name
			);
		}

		return array() === $fields ? $native : $fields;
	}

	/**
	 * A starting mapping using HubSpot's own property names.
	 *
	 * @return FieldMap
	 */
	public function defaultMap(): FieldMap {
		return new FieldMap(
			array(
				'email'      => 'email',
				'first_name' => 'firstname',
				'last_name'  => 'lastname',
				'phone'      => 'phone',
				'company'    => 'company',
				'job_title'  => 'jobtitle',
				'website'    => 'website',
			)
		);
	}

	/**
	 * PATCH an existing contact.
	 *
	 * @param string                $id          Contact id.
	 * @param array<string, string> $properties  Properties.
	 * @param ConnectorCredentials  $credentials Stored credentials.
	 * @return SyncResult
	 */
	private function update( string $id, array $properties, ConnectorCredentials $credentials ): SyncResult {
		// Email is the identity, and PATCHing it back is how a typo in a
		// mapping becomes a merged contact.
		unset( $properties['email'] );

		$response = $this->http->patchJson(
			self::API_BASE . '/crm/v3/objects/contacts/' . rawurlencode( $id ),
			array( 'properties' => $properties ),
			$this->headers( $credentials )
		);

		return $response->ok()
			? SyncResult::success( $id, array( 'fields' => array_keys( $properties ) ), $response->status )
			: $this->failure( $response );
	}

	/**
	 * Run a token grant.
	 *
	 * @param ConnectorCredentials  $credentials Existing credentials.
	 * @param array<string, string> $fields      Form fields.
	 * @return AuthResult
	 */
	private function token( ConnectorCredentials $credentials, array $fields ): AuthResult {
		$response = $this->http->postForm( self::TOKEN_URL, $fields );

		if ( ! $response->ok() ) {
			return AuthResult::failed( $response->errorMessage() );
		}

		$access = $response->string( 'access_token' );

		if ( '' === $access ) {
			return AuthResult::failed( __( 'HubSpot returned no access token.', 'hiveclerk' ) );
		}

		$expiresIn = $response->get( 'expires_in' );
		$refresh   = $response->string( 'refresh_token' );

		return AuthResult::ok(
			$credentials->with(
				array_filter(
					array(
						'access_token'  => $access,
						// A refresh grant does not always return a new
						// refresh token, and overwriting the stored one
						// with an empty string would silently make the
						// connection un-refreshable an hour later.
						'refresh_token' => $refresh,
					),
					static fn ( string $value ): bool => '' !== $value
				),
				$this->expiry( is_numeric( $expiresIn ) ? (int) $expiresIn : 0 )
			)
		);
	}

	/**
	 * Turn a lifetime in seconds into an absolute expiry.
	 *
	 * @param int $seconds Lifetime.
	 * @return DateTimeImmutable|null
	 */
	private function expiry( int $seconds ): ?DateTimeImmutable {
		if ( $seconds <= 0 ) {
			return null;
		}

		return ( new DateTimeImmutable( '@' . ( $this->clock->now()->getTimestamp() + $seconds ) ) )
			->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Authorisation and content headers.
	 *
	 * @param ConnectorCredentials $credentials Stored credentials.
	 * @return array<string, string>
	 */
	private function headers( ConnectorCredentials $credentials ): array {
		return array( 'Authorization' => 'Bearer ' . $credentials->get( 'access_token' ) );
	}

	/**
	 * Turn a failed response into a sync result.
	 *
	 * @param ConnectorResponse $response Response.
	 * @return SyncResult
	 */
	private function failure( ConnectorResponse $response ): SyncResult {
		return $response->isRetryable()
			? SyncResult::transient( $response->errorMessage(), $response->status )
			: SyncResult::permanent( $response->errorMessage(), $response->status );
	}

	/**
	 * Pull the existing contact id out of a 409.
	 *
	 * HubSpot puts it in prose — "Contact already exists. Existing ID:
	 * 12345" — rather than in a field, so this reads what is actually
	 * sent rather than what an API would ideally send.
	 *
	 * @param ConnectorResponse $response Conflict response.
	 * @return string|null
	 */
	private static function conflictId( ConnectorResponse $response ): ?string {
		$id = $response->body['errorTokens']['existingObjectId'][0] ?? null;

		if ( is_string( $id ) && '' !== $id ) {
			return $id;
		}

		if ( 1 === preg_match( '/Existing ID:\s*(\d+)/i', $response->errorMessage(), $matches ) ) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * HubSpot properties are strings, whatever the source field was.
	 *
	 * @param array<string, mixed> $payload Mapped values.
	 * @return array<string, string>
	 */
	private function stringify( array $payload ): array {
		$properties = array();

		foreach ( $payload as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$properties[ $key ] = (string) $value;
			}
		}

		return $properties;
	}
}
