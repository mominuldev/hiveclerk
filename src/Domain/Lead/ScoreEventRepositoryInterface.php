<?php
/**
 * Score event repository contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

/**
 * Persistence for the append-only score log (D7 §5.2).
 *
 * There is no update and no delete by id. That is the whole point of the
 * table: a score breakdown that can be edited after the fact is not a
 * breakdown, it is a second opinion. Events go away only with the lead
 * they belong to.
 */
interface ScoreEventRepositoryInterface {

	/**
	 * Append one event.
	 *
	 * @param ScoreEvent $event Event.
	 * @return ScoreEvent
	 */
	public function append( ScoreEvent $event ): ScoreEvent;

	/**
	 * Every event for a lead, oldest first.
	 *
	 * @param int $leadId Lead storage id.
	 * @param int $limit  Maximum rows.
	 * @return array<int, ScoreEvent>
	 */
	public function forLead( int $leadId, int $limit = 200 ): array;

	/**
	 * The rule ids a lead has already been scored on.
	 *
	 * Read before every scoring pass, so it returns ids rather than
	 * events: the pass needs to know what has been paid, not what it
	 * said.
	 *
	 * @param int $leadId Lead storage id.
	 * @return array<int, string>
	 */
	public function awardedRuleIds( int $leadId ): array;

	/**
	 * The sum of every event for a lead.
	 *
	 * The materialised total on the lead is the fast path; this is the
	 * arithmetic it is supposed to agree with, and a repair reads it.
	 *
	 * @param int $leadId Lead storage id.
	 * @return int
	 */
	public function total( int $leadId ): int;

	/**
	 * Move every event from one lead onto another (FR-LED-08).
	 *
	 * @param int $from Lead being merged away.
	 * @param int $to   Surviving lead.
	 * @return int Rows moved.
	 */
	public function reassign( int $from, int $to ): int;

	/**
	 * Delete every event for a lead.
	 *
	 * @param int $leadId Lead storage id.
	 * @return int Rows deleted.
	 */
	public function deleteForLead( int $leadId ): int;
}
