<?php
/**
 * Activity repository contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

/**
 * Persistence for the lead timeline.
 */
interface ActivityRepositoryInterface {

	/**
	 * Record one activity.
	 *
	 * @param Activity $activity Activity.
	 * @return Activity
	 */
	public function record( Activity $activity ): Activity;

	/**
	 * A lead's timeline, newest first.
	 *
	 * Includes activity recorded against the visitor before they were
	 * identified, which is where the page views live.
	 *
	 * @param int            $leadId    Lead storage id.
	 * @param array<int, int> $visitorIds Visitors stitched to this lead.
	 * @param int            $limit     Maximum rows.
	 * @return array<int, Activity>
	 */
	public function timeline( int $leadId, array $visitorIds = array(), int $limit = 100 ): array;

	/**
	 * Whether a lead already has an activity of a given type.
	 *
	 * Used to keep a threshold notification from being sent twice for
	 * the same lead. A salesperson emailed four times about one lead
	 * stops reading the emails.
	 *
	 * @param int          $leadId Lead storage id.
	 * @param ActivityType $type   Activity type.
	 * @return bool
	 */
	public function hasType( int $leadId, ActivityType $type ): bool;

	/**
	 * Move every activity from one lead onto another.
	 *
	 * @param int $from Lead being merged away.
	 * @param int $to   Surviving lead.
	 * @return int Rows moved.
	 */
	public function reassign( int $from, int $to ): int;

	/**
	 * Attach a visitor's activity to a lead once stitching resolves them.
	 *
	 * @param int $visitorId Visitor storage id.
	 * @param int $leadId    Lead storage id.
	 * @return int Rows updated.
	 */
	public function attachVisitor( int $visitorId, int $leadId ): int;

	/**
	 * Delete every activity for a lead.
	 *
	 * @param int $leadId Lead storage id.
	 * @return int Rows deleted.
	 */
	public function deleteForLead( int $leadId ): int;
}
