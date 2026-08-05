<?php
/**
 * Live-table reads the rollup cannot serve.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Analytics;

use Hiveclerk\Domain\Shared\DateRange;

/**
 * The two reports that cannot be pre-aggregated, and why.
 *
 * The funnel counts populations that overlap — a conversation that became
 * a lead is still a conversation — so it cannot be stored as five daily
 * counters and added up: summing "engaged" across 30 days counts one
 * visitor who came back three times as three engaged conversations, which
 * is correct, while summing "won" across the same days counts a deal
 * once, which is also correct, and the ratio between them is then
 * meaningless. It is computed over the whole range in one pass instead.
 *
 * Topics cannot be pre-aggregated because grouping questions requires
 * normalising their text, and the normalisation is a product decision
 * that will change. Storing yesterday's grouping would freeze a rule we
 * are still learning.
 */
interface ReportSourceInterface {

	/**
	 * The five funnel populations over a range.
	 *
	 * @param DateRange $range          Span.
	 * @param int       $qualifiedScore Score at which a lead counts as qualified.
	 * @param int|null  $agentId        Restrict to one clerk.
	 * @return array{conversations: int, engaged: int, captured: int, qualified: int, won: int}
	 */
	public function funnel( DateRange $range, int $qualifiedScore, ?int $agentId = null ): array;

	/**
	 * The opening question of each conversation in a range.
	 *
	 * The first thing a visitor types is the question they came with.
	 * Every later message is a follow-up, a "yes", or a thank-you, and
	 * counting those produces a top-questions list whose top entry is
	 * "thanks".
	 *
	 * Bounded: the caller is told when the answer was sampled rather than
	 * exhaustive, because a top-questions list built from a slice of a
	 * busy month is still useful and a query that loads a million rows is
	 * not.
	 *
	 * @param DateRange $range   Span.
	 * @param int       $limit   Most conversations to read.
	 * @param int|null  $agentId Restrict to one clerk.
	 * @return array<int, string>
	 */
	public function openingQuestions( DateRange $range, int $limit, ?int $agentId = null ): array;

	/**
	 * How many conversations the range holds, ignoring the sampling limit.
	 *
	 * @param DateRange $range   Span.
	 * @param int|null  $agentId Restrict to one clerk.
	 * @return int
	 */
	public function conversationCount( DateRange $range, ?int $agentId = null ): int;
}
