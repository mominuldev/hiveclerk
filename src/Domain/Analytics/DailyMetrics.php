<?php
/**
 * One day of rolled-up activity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Analytics;

/**
 * Everything the dashboard needs about one day, already counted.
 *
 * The whole reason this object exists is that no screen may reach the
 * messages table to draw a chart. A site with 50,000 conversations has
 * several million message rows, and a dashboard that aggregates them on
 * request is a dashboard the customer stops opening.
 *
 * `agentId` is nullable and the null carries meaning: it is the site-wide
 * row for that day, not a row whose clerk is unknown. Site-wide is stored
 * rather than derived by summing the per-clerk rows because conversations
 * and visitors do not sum — one visitor who spoke to two clerks is one
 * unique visitor for the site and one for each clerk, and adding the
 * per-clerk figures would report two.
 */
final class DailyMetrics {

	/**
	 * Construct.
	 *
	 * @param string     $date            UTC calendar day, Y-m-d.
	 * @param int|null   $agentId         Clerk, or null for the site-wide row.
	 * @param int        $conversations   Conversations started.
	 * @param int        $messages        Messages of every role.
	 * @param int        $uniqueVisitors  Distinct visitors seen.
	 * @param int        $leadsCaptured   Leads first captured.
	 * @param int        $leadsQualified  Leads that crossed the qualified band.
	 * @param int        $handoffs        Conversations handed to a person.
	 * @param int        $resolvedByAi    Conversations closed without one.
	 * @param int        $positiveRatings Thumbs up on a reply.
	 * @param int        $negativeRatings Thumbs down on a reply.
	 * @param int        $unanswered      Questions no confident chunk answered.
	 * @param int        $tokensIn        Input tokens billed.
	 * @param int        $tokensOut       Output tokens billed.
	 * @param float      $cost            Spend in USD for the priced calls.
	 * @param int|null   $avgLatencyMs    Mean reply latency, null when nothing was answered.
	 * @param int        $unpriced        Calls whose cost is not known, and so are not in $cost.
	 *
	 * `$unpriced` is last rather than beside `$cost`, where it belongs
	 * semantically, because two call sites build this positionally and
	 * moving the tail would have silently shifted a latency into a count.
	 * It travels with `$cost` everywhere else: a spend figure without it is
	 * a number that quietly omits whatever could not be priced.
	 */
	public function __construct(
		public readonly string $date,
		public readonly ?int $agentId = null,
		public readonly int $conversations = 0,
		public readonly int $messages = 0,
		public readonly int $uniqueVisitors = 0,
		public readonly int $leadsCaptured = 0,
		public readonly int $leadsQualified = 0,
		public readonly int $handoffs = 0,
		public readonly int $resolvedByAi = 0,
		public readonly int $positiveRatings = 0,
		public readonly int $negativeRatings = 0,
		public readonly int $unanswered = 0,
		public readonly int $tokensIn = 0,
		public readonly int $tokensOut = 0,
		public readonly float $cost = 0.0,
		public readonly ?int $avgLatencyMs = null,
		public readonly int $unpriced = 0
	) {
	}

	/**
	 * An empty day, so a series can carry the days nothing happened.
	 *
	 * @param string   $date    Y-m-d.
	 * @param int|null $agentId Clerk.
	 * @return self
	 */
	public static function empty( string $date, ?int $agentId = null ): self {
		return new self( $date, $agentId );
	}

	/**
	 * The share of conversations the clerk closed without a person.
	 *
	 * Returns null rather than zero on a day with no conversations. Zero
	 * would read as "the clerk deflected nothing", which is a judgement
	 * about a day on which it was never asked anything.
	 *
	 * @return float|null Fraction between 0 and 1.
	 */
	public function deflectionRate(): ?float {
		if ( 0 === $this->conversations ) {
			return null;
		}

		return $this->resolvedByAi / $this->conversations;
	}

	/**
	 * Spend per conversation, or null when there were none.
	 *
	 * @return float|null
	 */
	public function costPerConversation(): ?float {
		if ( 0 === $this->conversations ) {
			return null;
		}

		return $this->cost / $this->conversations;
	}

	/**
	 * The same day with two metrics added together.
	 *
	 * Used to fold today's live figures over yesterday's stored rollup.
	 * Averages are weighted by the message counts that produced them
	 * rather than averaged again, because the mean of two means is not a
	 * mean of anything.
	 *
	 * @param self $other Metrics for the same day and clerk.
	 * @return self
	 */
	public function plus( self $other ): self {
		return new self(
			$this->date,
			$this->agentId,
			$this->conversations + $other->conversations,
			$this->messages + $other->messages,
			$this->uniqueVisitors + $other->uniqueVisitors,
			$this->leadsCaptured + $other->leadsCaptured,
			$this->leadsQualified + $other->leadsQualified,
			$this->handoffs + $other->handoffs,
			$this->resolvedByAi + $other->resolvedByAi,
			$this->positiveRatings + $other->positiveRatings,
			$this->negativeRatings + $other->negativeRatings,
			$this->unanswered + $other->unanswered,
			$this->tokensIn + $other->tokensIn,
			$this->tokensOut + $other->tokensOut,
			$this->cost + $other->cost,
			self::weightedLatency( $this, $other ),
			$this->unpriced + $other->unpriced
		);
	}

	/**
	 * Mean latency across two slices, weighted by their message counts.
	 *
	 * @param self $a First slice.
	 * @param self $b Second slice.
	 * @return int|null
	 */
	private static function weightedLatency( self $a, self $b ): ?int {
		if ( null === $a->avgLatencyMs ) {
			return $b->avgLatencyMs;
		}

		if ( null === $b->avgLatencyMs ) {
			return $a->avgLatencyMs;
		}

		$total = $a->messages + $b->messages;

		if ( 0 === $total ) {
			return (int) round( ( $a->avgLatencyMs + $b->avgLatencyMs ) / 2 );
		}

		return (int) round(
			( ( $a->avgLatencyMs * $a->messages ) + ( $b->avgLatencyMs * $b->messages ) ) / $total
		);
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'date'             => $this->date,
			'agent_id'         => $this->agentId,
			'conversations'    => $this->conversations,
			'messages'         => $this->messages,
			'unique_visitors'  => $this->uniqueVisitors,
			'leads_captured'   => $this->leadsCaptured,
			'leads_qualified'  => $this->leadsQualified,
			'handoffs'         => $this->handoffs,
			'resolved_by_ai'   => $this->resolvedByAi,
			'positive_ratings' => $this->positiveRatings,
			'negative_ratings' => $this->negativeRatings,
			'unanswered'       => $this->unanswered,
			'tokens_in'        => $this->tokensIn,
			'tokens_out'       => $this->tokensOut,
			'cost'             => round( $this->cost, 6 ),
			// Beside the spend, always. A cost figure on its own is a
			// number that silently omits whatever could not be priced.
			'unpriced'         => $this->unpriced,
			'avg_latency_ms'   => $this->avgLatencyMs,
		);
	}
}
