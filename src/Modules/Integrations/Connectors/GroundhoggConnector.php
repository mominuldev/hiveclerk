<?php
/**
 * Groundhogg connector.
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
 * Groundhogg, in-process (FR-CRM-03).
 *
 * Same shape as the FluentCRM connector and for the same reasons, with
 * one difference worth naming: Groundhogg splits a contact between fixed
 * columns and arbitrary meta, and its meta store accepts any key. That
 * makes "map this to a custom field" free — there is nothing to create
 * first — and it makes an unvalidated mapping dangerous in a way
 * FluentCRM's is not, because a typo does not fail, it silently writes a
 * meta key nobody will ever read. The mapping screen therefore offers the
 * meta keys already in use rather than a free-text box.
 */
final class GroundhoggConnector extends AbstractConnector {

	/**
	 * Prefix marking a mapped target as contact meta rather than a column.
	 */
	private const META_PREFIX = 'meta:';

	/**
	 * Columns on the contacts table this connector will write.
	 *
	 * @var array<int, string>
	 */
	private const WRITABLE = array( 'email', 'first_name', 'last_name' );

	/**
	 * What the grid draws.
	 *
	 * @return ConnectorDescriptor
	 */
	public function descriptor(): ConnectorDescriptor {
		return new ConnectorDescriptor(
			id: 'groundhogg',
			name: 'Groundhogg',
			kind: ConnectorDescriptor::KIND_CRM,
			auth: ConnectorDescriptor::AUTH_LOCAL,
			summary: __( 'Create Groundhogg contacts on this site as leads qualify. No API key, nothing leaves the server.', 'hiveclerk' ),
			isPro: true,
			settings: array(),
			docsUrl: 'https://hiveclerk.com/docs/integrations/groundhogg'
		);
	}

	/**
	 * Whether Groundhogg is installed and active.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		return class_exists( '\Groundhogg\Contact' ) && function_exists( '\Groundhogg\get_contactdata' );
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
				__( 'Groundhogg is not active on this site. Install and activate it, then connect again.', 'hiveclerk' )
			);
		}

		return AuthResult::ok( ConnectorCredentials::none(), get_bloginfo( 'name' ) );
	}

	/**
	 * Prove the contact layer answers.
	 *
	 * @param ConnectorCredentials $credentials Ignored.
	 * @return TestResult
	 */
	public function test( ConnectorCredentials $credentials ): TestResult {
		unset( $credentials );

		if ( ! $this->isAvailable() ) {
			return TestResult::fail( __( 'Groundhogg is not active on this site.', 'hiveclerk' ) );
		}

		try {
			// A lookup for an address that cannot exist. Groundhogg answers
			// false, which is the proof the query layer is up — and it
			// writes nothing, which a create-then-delete test would.
			\Groundhogg\get_contactdata( 'hiveclerk-connection-test@invalid' );

			return TestResult::pass( __( 'Groundhogg answered.', 'hiveclerk' ), get_bloginfo( 'name' ) );
		} catch ( Throwable $e ) {
			return TestResult::fail(
				sprintf(
					/* translators: %s: error message from Groundhogg. */
					__( 'Groundhogg raised an error: %s', 'hiveclerk' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Create or update a contact.
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
			return SyncResult::transient( __( 'Groundhogg is not active on this site.', 'hiveclerk' ) );
		}

		if ( null === $lead->email ) {
			return SyncResult::permanent( __( 'Groundhogg identifies contacts by email and this lead has none.', 'hiveclerk' ) );
		}

		$columns = array();
		$meta    = array();

		foreach ( $payload as $target => $value ) {
			if ( str_starts_with( $target, self::META_PREFIX ) ) {
				$meta[ substr( $target, strlen( self::META_PREFIX ) ) ] = $value;

				continue;
			}

			if ( in_array( $target, self::WRITABLE, true ) ) {
				$columns[ $target ] = $value;
			}
		}

		$columns['email'] = $lead->email;

		try {
			$existing = \Groundhogg\get_contactdata( $lead->email );
			$contact  = is_object( $existing ) ? $existing : new \Groundhogg\Contact( $columns );

			if ( is_object( $existing ) && method_exists( $contact, 'update' ) ) {
				$contact->update( $columns );
			}

			if ( ! method_exists( $contact, 'get_id' ) ) {
				return SyncResult::permanent( __( 'Groundhogg returned something that is not a contact.', 'hiveclerk' ) );
			}

			$id = $contact->get_id();

			if ( ! is_numeric( $id ) || (int) $id <= 0 ) {
				return SyncResult::permanent( __( 'Groundhogg did not create the contact.', 'hiveclerk' ) );
			}

			foreach ( $meta as $key => $value ) {
				if ( method_exists( $contact, 'update_meta' ) ) {
					$contact->update_meta( $key, $value );
				}
			}

			$tags = $this->configuredTags();

			if ( array() !== $tags && method_exists( $contact, 'add_tag' ) ) {
				$contact->add_tag( $tags );
			}

			return SyncResult::success(
				(string) (int) $id,
				array( 'fields' => array_merge( array_keys( $columns ), array_keys( $meta ) ) )
			);
		} catch ( Throwable $e ) {
			return SyncResult::transient( $e->getMessage() );
		}
	}

	/**
	 * Attach a note to the contact.
	 *
	 * @param Lead                 $lead        The lead.
	 * @param Activity             $activity    What happened.
	 * @param ConnectorCredentials $credentials Ignored.
	 * @param string|null          $externalId  Contact id.
	 * @return SyncResult
	 */
	public function pushActivity(
		Lead $lead,
		Activity $activity,
		ConnectorCredentials $credentials,
		?string $externalId
	): SyncResult {
		unset( $lead, $credentials );

		if ( ! $this->isAvailable() || null === $externalId ) {
			return SyncResult::success();
		}

		try {
			$contact = new \Groundhogg\Contact( (int) $externalId );

			// No method_exists guard: a Groundhogg old enough to lack
			// add_note() raises an Error, which is a Throwable, which the
			// catch below already handles. Two mechanisms for one failure
			// is one more than the failure deserves.
			$contact->add_note(
				trim( $activity->title . "\n" . (string) $activity->body ),
				'hiveclerk'
			);

			return SyncResult::success( $externalId );
		} catch ( Throwable $e ) {
			return SyncResult::permanent( $e->getMessage() );
		}
	}

	/**
	 * Columns plus the meta keys this site already uses.
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
		);

		foreach ( $this->metaKeys() as $key ) {
			$fields[] = new ConnectorField(
				self::META_PREFIX . $key,
				sprintf(
					/* translators: %s: Groundhogg contact meta key. */
					__( 'Meta: %s', 'hiveclerk' ),
					$key
				),
				'text',
				true
			);
		}

		return $fields;
	}

	/**
	 * A starting mapping using Groundhogg's own names.
	 *
	 * @return FieldMap
	 */
	public function defaultMap(): FieldMap {
		return new FieldMap(
			array(
				'email'      => 'email',
				'first_name' => 'first_name',
				'last_name'  => 'last_name',
				'phone'      => self::META_PREFIX . 'primary_phone',
				'company'    => self::META_PREFIX . 'company_name',
				'job_title'  => self::META_PREFIX . 'job_title',
			)
		);
	}

	/**
	 * Meta keys the mapping screen offers.
	 *
	 * Groundhogg's own standard keys plus anything a site added through
	 * the filter. Not read from the database: enumerating every meta key
	 * on a contacts table with a hundred thousand rows is a full scan on
	 * a screen that renders on every visit to Integrations.
	 *
	 * @return array<int, string>
	 */
	private function metaKeys(): array {
		$keys = array(
			'primary_phone',
			'primary_phone_extension',
			'mobile_phone',
			'company_name',
			'job_title',
			'company_address',
			'street_address_1',
			'street_address_2',
			'city',
			'region',
			'postal_zip',
			'country',
			'lead_source',
			'source_page',
		);

		/**
		 * Groundhogg contact meta keys the mapping screen offers.
		 *
		 * @param array<int, string> $keys Meta keys.
		 */
		$filtered = apply_filters( 'hiveclerk/crm/groundhogg/meta_keys', $keys );

		if ( ! is_array( $filtered ) ) {
			return $keys;
		}

		$clean = array();

		foreach ( $filtered as $key ) {
			if ( is_string( $key ) && '' !== $key ) {
				$clean[] = $key;
			}
		}

		return $clean;
	}

	/**
	 * Tags applied to every pushed contact.
	 *
	 * @return array<int, string>
	 */
	private function configuredTags(): array {
		/**
		 * Groundhogg tags to apply to every pushed contact.
		 *
		 * @param array<int, string> $tags Tag names or ids.
		 */
		$tags = apply_filters( 'hiveclerk/crm/groundhogg/tags', array() );

		if ( ! is_array( $tags ) ) {
			return array();
		}

		$clean = array();

		foreach ( $tags as $tag ) {
			if ( is_string( $tag ) || is_int( $tag ) ) {
				$clean[] = (string) $tag;
			}
		}

		return $clean;
	}
}
