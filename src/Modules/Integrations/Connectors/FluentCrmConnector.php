<?php
/**
 * FluentCRM connector.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Connectors;

use Hiveclerk\Domain\Integration\AuthResult;
use Hiveclerk\Domain\Integration\ConnectorCredentials;
use Hiveclerk\Domain\Integration\ConnectorDescriptor;
use Hiveclerk\Domain\Integration\ConnectorField;
use Hiveclerk\Domain\Integration\FieldMap;
use Hiveclerk\Domain\Integration\SyncResult;
use Hiveclerk\Domain\Integration\TestResult;
use Hiveclerk\Domain\Lead\Activity;
use Hiveclerk\Domain\Lead\Lead;
use Throwable;

/**
 * FluentCRM, in-process (FR-CRM-02).
 *
 * Shipped first for the reason Q-4 gives: it is the only connector with
 * no network between us and the destination. A local push either works or
 * throws in the same request, which makes the whole pipeline — mapping,
 * trigger evaluation, log rows, the retry policy's success path —
 * demonstrable on a laptop before an OAuth flow is anywhere near it.
 *
 * ## Everything here is guarded twice
 *
 * FluentCRM is not a dependency. It may be absent, it may be present at a
 * version whose API differs, and it may be deactivated between the moment
 * a job was queued and the moment it runs. `class_exists` covers the
 * first two; the `Throwable` catch covers the third and everything else,
 * because a fatal inside a background job takes the whole queue run with
 * it and the next thing the customer notices is that indexing stopped.
 */
final class FluentCrmConnector extends AbstractConnector {

	/**
	 * Prefix marking a mapped target as a FluentCRM custom field.
	 */
	private const CUSTOM_PREFIX = 'custom:';

	/**
	 * Native subscriber columns this connector will write.
	 *
	 * A whitelist rather than passing the mapping through: FluentCRM's
	 * subscriber model updates any column it is handed, and a mapping row
	 * pointing at `status` would silently unsubscribe every contact the
	 * plugin pushed.
	 */
	private const WRITABLE = array(
		'email',
		'first_name',
		'last_name',
		'phone',
		'designation',
		'address_line_1',
		'address_line_2',
		'city',
		'state',
		'postal_code',
		'country',
		'source',
	);

	/**
	 * What the grid draws.
	 *
	 * @return ConnectorDescriptor
	 */
	public function descriptor(): ConnectorDescriptor {
		return new ConnectorDescriptor(
			id: 'fluentcrm',
			name: 'FluentCRM',
			kind: ConnectorDescriptor::KIND_CRM,
			auth: ConnectorDescriptor::AUTH_LOCAL,
			summary: __( 'Push qualified leads straight into FluentCRM on this site. No API key, nothing leaves the server.', 'hiveclerk' ),
			isPro: true,
			settings: array(),
			docsUrl: 'https://hiveclerk.com/docs/integrations/fluentcrm'
		);
	}

	/**
	 * Whether FluentCRM is installed and active.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		return function_exists( 'FluentCrmApi' );
	}

	/**
	 * Nothing to authenticate against — but absence is still a failure.
	 *
	 * @param ConnectorCredentials $credentials Ignored.
	 * @return AuthResult
	 */
	public function authenticate( ConnectorCredentials $credentials ): AuthResult {
		unset( $credentials );

		if ( ! $this->isAvailable() ) {
			return AuthResult::failed(
				__( 'FluentCRM is not active on this site. Install and activate it, then connect again.', 'hiveclerk' )
			);
		}

		return AuthResult::ok( ConnectorCredentials::none(), get_bloginfo( 'name' ) );
	}

	/**
	 * Prove the model layer answers.
	 *
	 * @param ConnectorCredentials $credentials Ignored.
	 * @return TestResult
	 */
	public function test( ConnectorCredentials $credentials ): TestResult {
		unset( $credentials );

		if ( ! $this->isAvailable() ) {
			return TestResult::fail( __( 'FluentCRM is not active on this site.', 'hiveclerk' ) );
		}

		try {
			$contacts = $this->contacts();

			if ( null === $contacts ) {
				return TestResult::fail( __( 'FluentCRM is active but its contacts API did not answer.', 'hiveclerk' ) );
			}

			return TestResult::pass(
				__( 'FluentCRM answered.', 'hiveclerk' ),
				get_bloginfo( 'name' ),
				$this->subscriberCount()
			);
		} catch ( Throwable $e ) {
			return TestResult::fail(
				sprintf(
					/* translators: %s: error message from FluentCRM. */
					__( 'FluentCRM raised an error: %s', 'hiveclerk' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Create or update a subscriber.
	 *
	 * @param Lead                 $lead        The lead.
	 * @param FieldMap             $map         Field mapping.
	 * @param ConnectorCredentials $credentials Ignored.
	 * @param array<string, mixed> $payload     Mapped values.
	 * @return SyncResult
	 */
	public function pushContact(
		Lead $lead,
		FieldMap $map,
		ConnectorCredentials $credentials,
		array $payload
	): SyncResult {
		unset( $map, $credentials );

		if ( ! $this->isAvailable() ) {
			// Retryable: the plugin being deactivated during a queue run is
			// temporary far more often than it is permanent, and a lead
			// dropped for it would never be pushed again.
			return SyncResult::transient( __( 'FluentCRM is not active on this site.', 'hiveclerk' ) );
		}

		if ( null === $lead->email ) {
			return SyncResult::permanent( __( 'FluentCRM identifies contacts by email and this lead has none.', 'hiveclerk' ) );
		}

		$data   = array( 'email' => $lead->email );
		$custom = array();

		foreach ( $payload as $target => $value ) {
			if ( str_starts_with( $target, self::CUSTOM_PREFIX ) ) {
				$custom[ substr( $target, strlen( self::CUSTOM_PREFIX ) ) ] = $value;

				continue;
			}

			if ( in_array( $target, self::WRITABLE, true ) ) {
				$data[ $target ] = $value;
			}
		}

		if ( array() !== $custom ) {
			$data['custom_values'] = $custom;
		}

		$tags = $this->configuredIds( 'tags' );

		if ( array() !== $tags ) {
			$data['tags'] = $tags;
		}

		$lists = $this->configuredIds( 'lists' );

		if ( array() !== $lists ) {
			$data['lists'] = $lists;
		}

		try {
			$contacts = $this->contacts();

			if ( null === $contacts || ! method_exists( $contacts, 'createOrUpdate' ) ) {
				return SyncResult::transient( __( 'FluentCRM is active but its contacts API did not answer.', 'hiveclerk' ) );
			}

			/** @var object|null $subscriber */
			$subscriber = $contacts->createOrUpdate( $data );

			if ( null === $subscriber ) {
				return SyncResult::permanent( __( 'FluentCRM refused the contact.', 'hiveclerk' ) );
			}

			$id = property_exists( $subscriber, 'id' ) ? $subscriber->id : null;

			return SyncResult::success(
				is_scalar( $id ) ? (string) $id : null,
				array( 'fields' => array_keys( $data ) )
			);
		} catch ( Throwable $e ) {
			return SyncResult::transient( $e->getMessage() );
		}
	}

	/**
	 * Attach a note to the subscriber.
	 *
	 * @param Lead                 $lead        The lead.
	 * @param Activity             $activity    What happened.
	 * @param ConnectorCredentials $credentials Ignored.
	 * @param string|null          $externalId  Subscriber id.
	 * @return SyncResult
	 */
	public function pushActivity(
		Lead $lead,
		Activity $activity,
		ConnectorCredentials $credentials,
		?string $externalId
	): SyncResult {
		unset( $lead, $credentials );

		if ( ! $this->isAvailable() || null === $externalId || ! class_exists( '\FluentCrm\App\Models\SubscriberNote' ) ) {
			return SyncResult::success();
		}

		try {
			\FluentCrm\App\Models\SubscriberNote::create(
				array(
					'subscriber_id' => (int) $externalId,
					'type'          => 'note',
					'title'         => $activity->title,
					'description'   => (string) $activity->body,
					'created_by'    => 0,
				)
			);

			return SyncResult::success( $externalId );
		} catch ( Throwable $e ) {
			// A note that did not attach is not worth failing a sync over —
			// the contact is already there, which is the part that matters.
			return SyncResult::permanent( $e->getMessage() );
		}
	}

	/**
	 * Native columns plus whatever custom fields this site defined.
	 *
	 * @param ConnectorCredentials $credentials Ignored.
	 * @return array<int, ConnectorField>
	 */
	public function availableFields( ConnectorCredentials $credentials ): array {
		unset( $credentials );

		$fields = array(
			new ConnectorField( 'email', __( 'Email', 'hiveclerk' ), 'email', false, true, true ),
			new ConnectorField( 'first_name', __( 'First name', 'hiveclerk' ) ),
			new ConnectorField( 'last_name', __( 'Last name', 'hiveclerk' ) ),
			new ConnectorField( 'phone', __( 'Phone', 'hiveclerk' ), 'phone' ),
			new ConnectorField( 'designation', __( 'Designation', 'hiveclerk' ) ),
			new ConnectorField( 'address_line_1', __( 'Address line 1', 'hiveclerk' ) ),
			new ConnectorField( 'address_line_2', __( 'Address line 2', 'hiveclerk' ) ),
			new ConnectorField( 'city', __( 'City', 'hiveclerk' ) ),
			new ConnectorField( 'state', __( 'State', 'hiveclerk' ) ),
			new ConnectorField( 'postal_code', __( 'Postal code', 'hiveclerk' ) ),
			new ConnectorField( 'country', __( 'Country', 'hiveclerk' ) ),
			new ConnectorField( 'source', __( 'Source', 'hiveclerk' ) ),
		);

		foreach ( $this->customFields() as $field ) {
			$slug  = isset( $field['slug'] ) && is_string( $field['slug'] ) ? $field['slug'] : '';
			$label = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : $slug;

			if ( '' === $slug ) {
				continue;
			}

			$fields[] = new ConnectorField(
				self::CUSTOM_PREFIX . $slug,
				$label,
				'text',
				true
			);
		}

		return $fields;
	}

	/**
	 * A starting mapping that uses FluentCRM's own column names.
	 *
	 * @return FieldMap
	 */
	public function defaultMap(): FieldMap {
		return new FieldMap(
			array(
				'email'      => 'email',
				'first_name' => 'first_name',
				'last_name'  => 'last_name',
				'phone'      => 'phone',
				'job_title'  => 'designation',
				'source'     => 'source',
			)
		);
	}

	/**
	 * FluentCRM's contacts API, or null when it is unreachable.
	 *
	 * @return object|null
	 */
	private function contacts(): ?object {
		if ( ! function_exists( 'FluentCrmApi' ) ) {
			return null;
		}

		$api = FluentCrmApi( 'contacts' );

		return is_object( $api ) ? $api : null;
	}

	/**
	 * Custom contact fields defined on this site.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function customFields(): array {
		if ( ! class_exists( '\FluentCrm\App\Services\Helper' ) ) {
			return array();
		}

		try {
			$fields = \FluentCrm\App\Services\Helper::getContactCustomFields();
		} catch ( Throwable $e ) {
			unset( $e );

			return array();
		}

		if ( ! is_array( $fields ) ) {
			return array();
		}

		$clean = array();

		foreach ( $fields as $field ) {
			if ( is_array( $field ) ) {
				$clean[] = $field;
			}
		}

		return $clean;
	}

	/**
	 * How many subscribers already exist.
	 *
	 * @return int|null
	 */
	private function subscriberCount(): ?int {
		if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
			return null;
		}

		try {
			return (int) \FluentCrm\App\Models\Subscriber::count();
		} catch ( Throwable $e ) {
			unset( $e );

			return null;
		}
	}

	/**
	 * Tag or list ids the operator chose on the mapping screen.
	 *
	 * Read from a filter rather than the integration record because this
	 * connector is stateless by contract. The integration service passes
	 * its options through the same filter before every push.
	 *
	 * @param string $kind tags or lists.
	 * @return array<int, int>
	 */
	private function configuredIds( string $kind ): array {
		/**
		 * FluentCRM tags or lists to apply to every pushed contact.
		 *
		 * @param array<int, int> $ids  Identifiers.
		 * @param string          $kind Either 'tags' or 'lists'.
		 */
		$ids = apply_filters( 'hiveclerk/crm/fluentcrm/' . $kind, array(), $kind );

		if ( ! is_array( $ids ) ) {
			return array();
		}

		$clean = array();

		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) ) {
				$clean[] = (int) $id;
			}
		}

		return $clean;
	}
}
