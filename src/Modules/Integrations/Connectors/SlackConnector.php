<?php
/**
 * Slack connector.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Connectors;

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

/**
 * Slack incoming webhooks (FR-CRM-09).
 *
 * ## Why this is a connector rather than part of the alerting settings
 *
 * Sprint 7 already posts a threshold alert to a Slack webhook from
 * `LeadNotifier`, and that stays: it fires once per lead, ever, and it
 * answers "somebody should look at this". This is the other thing —
 * every lead that meets the sync trigger, on the same schedule and with
 * the same retry policy as a CRM push, appearing in the sync log beside
 * them. A team that wants both gets both; a team that wants only the
 * alert never connects this.
 *
 * ## Why the message is fields rather than prose
 *
 * The mapping screen decides what a Slack message contains, exactly as it
 * decides what HubSpot receives. A sales channel that wants score and
 * company and nothing else gets that, rather than a paragraph written by
 * us that mentions things the team does not care about.
 */
final class SlackConnector extends AbstractConnector {

	/**
	 * Construct.
	 *
	 * @param ConnectorHttp $http Outbound HTTP.
	 */
	public function __construct( private readonly ConnectorHttp $http ) {
	}

	/**
	 * What the grid draws.
	 *
	 * @return ConnectorDescriptor
	 */
	public function descriptor(): ConnectorDescriptor {
		return new ConnectorDescriptor(
			id: 'slack',
			name: 'Slack',
			kind: ConnectorDescriptor::KIND_NOTIFICATION,
			auth: ConnectorDescriptor::AUTH_URL,
			summary: __( 'Post qualified leads into a Slack channel through an incoming webhook.', 'hiveclerk' ),
			isPro: false,
			settings: array(
				new ConnectorSetting(
					key: 'url',
					label: __( 'Incoming webhook URL', 'hiveclerk' ),
					type: 'password',
					secret: true,
					required: true,
					help: __( 'From Slack, under Incoming Webhooks. Stored encrypted — it is a credential, not a setting.', 'hiveclerk' ),
					placeholder: 'https://hooks.slack.com/services/…'
				),
			),
			docsUrl: 'https://hiveclerk.com/docs/integrations/slack'
		);
	}

	/**
	 * Accept a Slack webhook URL.
	 *
	 * @param ConnectorCredentials $credentials Submitted settings.
	 * @return AuthResult
	 */
	public function authenticate( ConnectorCredentials $credentials ): AuthResult {
		$url = $credentials->get( 'url' );

		if ( ! str_starts_with( $url, 'https://hooks.slack.com/' ) ) {
			return AuthResult::failed(
				__( 'That is not a Slack incoming webhook URL. It starts https://hooks.slack.com/.', 'hiveclerk' )
			);
		}

		return AuthResult::ok( $credentials, 'Slack' );
	}

	/**
	 * Post a message the operator will see land.
	 *
	 * @param ConnectorCredentials $credentials Stored settings.
	 * @return TestResult
	 */
	public function test( ConnectorCredentials $credentials ): TestResult {
		$response = $this->http->postJson(
			$credentials->get( 'url' ),
			array( 'text' => __( 'Hiveclerk is connected. Qualified leads will appear in this channel.', 'hiveclerk' ) ),
			array(),
			false
		);

		return $response->ok()
			? TestResult::pass( __( 'Slack accepted the message. Check the channel.', 'hiveclerk' ), 'Slack' )
			: TestResult::fail( $response->errorMessage() );
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

		$lines = array( '*' . $lead->displayName() . '*' );

		foreach ( $payload as $label => $value ) {
			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}

			$lines[] = $label . ': ' . (string) $value;
		}

		$lines[] = '<' . $this->link( $lead ) . '|' . __( 'Open the lead', 'hiveclerk' ) . '>';

		$response = $this->http->postJson(
			$credentials->get( 'url' ),
			array( 'text' => implode( "\n", $lines ) ),
			array(),
			false
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
	 * The lead's own field names, used as message labels.
	 *
	 * @param ConnectorCredentials $credentials Ignored.
	 * @return array<int, ConnectorField>
	 */
	public function availableFields( ConnectorCredentials $credentials ): array {
		unset( $credentials );

		$fields = array();

		foreach ( FieldMap::SOURCES as $source ) {
			$fields[] = new ConnectorField( $source, $source );
		}

		return $fields;
	}

	/**
	 * What a sales channel actually wants to read.
	 *
	 * Not the transcript. A full conversation pasted into a channel is
	 * both unreadable and a copy of a visitor's words in a place with no
	 * retention policy.
	 *
	 * @return FieldMap
	 */
	public function defaultMap(): FieldMap {
		return new FieldMap(
			array(
				'email'   => 'Email',
				'phone'   => 'Phone',
				'company' => 'Company',
				'score'   => 'Score',
				'source'  => 'Captured by',
			)
		);
	}

	/**
	 * The admin URL for a lead.
	 *
	 * @param Lead $lead The lead.
	 * @return string
	 */
	private function link( Lead $lead ): string {
		return add_query_arg( array( 'page' => 'hiveclerk' ), admin_url( 'admin.php' ) )
			. '#/leads/' . $lead->uuid->value;
	}
}
