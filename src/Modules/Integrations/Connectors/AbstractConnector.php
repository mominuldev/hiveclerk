<?php
/**
 * Connector base class.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Connectors;

use Hiveclerk\Domain\Integration\ConnectorCredentials;
use Hiveclerk\Domain\Integration\CrmConnectorInterface;
use Hiveclerk\Domain\Integration\FieldMap;
use Hiveclerk\Domain\Integration\SyncResult;
use Hiveclerk\Domain\Lead\Activity;
use Hiveclerk\Domain\Lead\Lead;

/**
 * Defaults so a connector only writes what it actually differs on.
 *
 * `pushActivity` returning "skipped rather than failed" is the important
 * one. Not every destination has a notion of a note on a contact, and a
 * connector that cannot record an activity has not failed to record it —
 * treating that as an error would put a permanent red row in the sync log
 * for a feature the customer never asked for.
 */
abstract class AbstractConnector implements CrmConnectorInterface {

	/**
	 * Available unless a subclass says otherwise.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		return true;
	}

	/**
	 * Nothing to record, and that is not a failure.
	 *
	 * @param Lead                 $lead        The lead.
	 * @param Activity             $activity    What happened.
	 * @param ConnectorCredentials $credentials Stored credentials.
	 * @param string|null          $externalId  Contact id over there.
	 * @return SyncResult
	 */
	public function pushActivity(
		Lead $lead,
		Activity $activity,
		ConnectorCredentials $credentials,
		?string $externalId
	): SyncResult {
		unset( $lead, $activity, $credentials, $externalId );

		return SyncResult::success();
	}

	/**
	 * A sensible starting mapping.
	 *
	 * Name, address and phone, because those are the fields every
	 * destination has and the ones a salesperson needs before they can do
	 * anything at all with a contact.
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
			)
		);
	}

	/**
	 * Machine identifier, taken from the descriptor.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->descriptor()->id;
	}
}
