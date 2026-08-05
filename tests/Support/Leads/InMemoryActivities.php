<?php
/**
 * Timeline storage without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Leads;

use Hiveclerk\Domain\Lead\Activity;
use Hiveclerk\Domain\Lead\ActivityRepositoryInterface;
use Hiveclerk\Domain\Lead\ActivityType;

/**
 * Timeline storage without a database.
 *
 * @internal
 */
final class InMemoryActivities implements ActivityRepositoryInterface {

	/**
	 * Every recorded activity, in order.
	 *
	 * @var array<int, Activity>
	 */
	public array $recorded = array();

	public function record( Activity $activity ): Activity {
		$activity->id     = count( $this->recorded ) + 1;
		$this->recorded[] = $activity;

		return $activity;
	}

	public function timeline( int $leadId, array $visitorIds = array(), int $limit = 100 ): array {
		return array_values(
			array_filter(
				$this->recorded,
				static fn ( Activity $activity ): bool => $activity->leadId === $leadId
					|| ( null !== $activity->visitorId && in_array( $activity->visitorId, $visitorIds, true ) )
			)
		);
	}

	public function hasType( int $leadId, ActivityType $type ): bool {
		foreach ( $this->recorded as $activity ) {
			if ( $activity->leadId === $leadId && $activity->type === $type ) {
				return true;
			}
		}

		return false;
	}

	public function reassign( int $from, int $to ): int {
		$moved = 0;

		foreach ( $this->recorded as $activity ) {
			if ( $activity->leadId === $from ) {
				$activity->leadId = $to;
				++$moved;
			}
		}

		return $moved;
	}

	public function attachVisitor( int $visitorId, int $leadId ): int {
		$updated = 0;

		foreach ( $this->recorded as $activity ) {
			if ( $activity->visitorId === $visitorId && null === $activity->leadId ) {
				$activity->leadId = $leadId;
				++$updated;
			}
		}

		return $updated;
	}

	public function deleteForLead( int $leadId ): int {
		$before = count( $this->recorded );

		$this->recorded = array_values(
			array_filter(
				$this->recorded,
				static fn ( Activity $activity ): bool => $activity->leadId !== $leadId
			)
		);

		return $before - count( $this->recorded );
	}

	/**
	 * Activities of a given type, for assertions.
	 *
	 * @param ActivityType $type Type.
	 * @return array<int, Activity>
	 */
	public function ofType( ActivityType $type ): array {
		return array_values(
			array_filter(
				$this->recorded,
				static fn ( Activity $activity ): bool => $activity->type === $type
			)
		);
	}
}
