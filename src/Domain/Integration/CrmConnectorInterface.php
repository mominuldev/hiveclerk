<?php
/**
 * Connector contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

use Hiveclerk\Domain\Lead\Activity;
use Hiveclerk\Domain\Lead\Lead;

/**
 * Everything an integration has to be able to do (FR-CRM-01).
 *
 * ## Why credentials are a parameter rather than state
 *
 * D9 §5 sketches `authenticate(array $credentials)` followed by stateful
 * `test()` and `pushContact()`. Connectors here are container singletons
 * shared across a request, and a connector that remembers who it
 * authenticated as is one `$this->token` away from pushing site A's lead
 * into site B's CRM on a multisite install — the kind of bug that is
 * invisible until it is a data-protection incident.
 *
 * Passing credentials per call makes every connector stateless and makes
 * that whole class of mistake impossible to write. The signature is the
 * only place this deviates from the specification, and the deviation is
 * recorded here rather than in a commit message nobody reads.
 *
 * ## Nothing here throws for a failed push
 *
 * A CRM being down is an expected condition, not an exceptional one. It
 * comes back as a SyncResult carrying whether retrying could help, so the
 * retry policy has something to decide with. Exceptions are reserved for
 * a connector being asked to do something it structurally cannot.
 */
interface CrmConnectorInterface {

	/**
	 * Machine identifier, stored in the `provider` column.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * What the grid draws before anything is connected.
	 *
	 * @return ConnectorDescriptor
	 */
	public function descriptor(): ConnectorDescriptor;

	/**
	 * Whether this site could use this connector at all.
	 *
	 * False for a local connector whose plugin is not installed. The card
	 * still renders — D11 §8 shows Groundhogg as "Installed" or not — but
	 * the connect button says what is missing rather than failing on
	 * click with a fatal about an undefined class.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool;

	/**
	 * Turn submitted or exchanged credentials into stored ones.
	 *
	 * @param ConnectorCredentials $credentials What the operator or the OAuth exchange supplied.
	 * @return AuthResult
	 */
	public function authenticate( ConnectorCredentials $credentials ): AuthResult;

	/**
	 * Prove the credentials still reach the account they claim to.
	 *
	 * @param ConnectorCredentials $credentials Stored credentials.
	 * @return TestResult
	 */
	public function test( ConnectorCredentials $credentials ): TestResult;

	/**
	 * Create or update a contact.
	 *
	 * @param Lead                 $lead        The lead.
	 * @param FieldMap             $map         Field mapping.
	 * @param ConnectorCredentials $credentials Stored credentials.
	 * @param array<string, mixed> $payload     Mapped values, already built by the field mapper.
	 * @return SyncResult
	 */
	public function pushContact(
		Lead $lead,
		FieldMap $map,
		ConnectorCredentials $credentials,
		array $payload
	): SyncResult;

	/**
	 * Attach a note or event to an existing contact.
	 *
	 * @param Lead                 $lead        The lead.
	 * @param Activity             $activity    What happened.
	 * @param ConnectorCredentials $credentials Stored credentials.
	 * @param string|null          $externalId  Contact id over there, when known.
	 * @return SyncResult
	 */
	public function pushActivity(
		Lead $lead,
		Activity $activity,
		ConnectorCredentials $credentials,
		?string $externalId
	): SyncResult;

	/**
	 * Fields the mapping screen can point at.
	 *
	 * Fetched live where the API offers it, so a custom field created in
	 * the CRM this morning is mappable this afternoon. A connector that
	 * cannot reach its API returns its native fields rather than nothing:
	 * an empty mapping screen reads as a broken product.
	 *
	 * @param ConnectorCredentials $credentials Stored credentials.
	 * @return array<int, ConnectorField>
	 */
	public function availableFields( ConnectorCredentials $credentials ): array;

	/**
	 * The mapping a site gets before anybody edits one.
	 *
	 * An unmapped integration pushes an email address and nothing else,
	 * which is technically a contact and practically useless.
	 *
	 * @return FieldMap
	 */
	public function defaultMap(): FieldMap;
}
