<?php
/**
 * The monthly token cap and what it costs.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Agents\Services;

use DateTimeImmutable;
use Hiveclerk\Ai\PricingTable;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;

/**
 * Rolls the monthly counter over and says what the cap is worth in money
 * (FR-CLK-03).
 *
 * Two things live here that look like one. The **roll-over** is a
 * correctness concern: `tokens_used_month` is a counter with no memory of
 * which month it belongs to, so something has to zero it, and the only
 * moment we can be sure a clerk is being looked at is when it is read.
 * The **estimate** is a product concern: a cap expressed in tokens means
 * nothing to the person paying for it, and "500,000 tokens" and "about
 * $12.40 a month" are the same sentence to everybody except the model.
 *
 * Roll-over happens on read rather than on a schedule. A cron-driven
 * reset on a site whose cron does not run — which is a normal shared host
 * — leaves a clerk permanently exhausted from the month it first hit its
 * cap, and the customer's only symptom is a clerk that stopped answering.
 */
final class BudgetGuard {

	/**
	 * Fraction of the budget at which the admin starts warning.
	 *
	 * Early enough to raise the cap before anything stops, late enough
	 * that it is not the normal state of a healthy clerk.
	 */
	public const WARN_AT = 0.8;

	/**
	 * Construct.
	 *
	 * @param AgentRepositoryInterface $agents  Clerk storage.
	 * @param PricingTable             $pricing Published model prices.
	 * @param ClockInterface           $clock   Clock.
	 */
	public function __construct(
		private readonly AgentRepositoryInterface $agents,
		private readonly PricingTable $pricing,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Zero the counter when the clerk has crossed into a new month.
	 *
	 * Mutates and returns the same instance so a caller that already holds
	 * the clerk sees the reset without re-reading it.
	 *
	 * @param Agent $agent The clerk.
	 * @return Agent
	 */
	public function rollOver( Agent $agent ): Agent {
		if ( null === $agent->id ) {
			return $agent;
		}

		$now   = $this->clock->now();
		$start = $this->periodStart( $now );

		if ( null !== $agent->budgetResetAt && $agent->budgetResetAt >= $start ) {
			return $agent;
		}

		// A clerk that has never been reset and has spent nothing does not
		// need a write. On a site with twenty clerks that is twenty
		// pointless UPDATEs on the first page load of every month.
		if ( null === $agent->budgetResetAt && 0 === $agent->tokensUsedMonth ) {
			$agent->budgetResetAt = $start;

			return $agent;
		}

		$this->agents->resetUsage( $agent->id, $start->format( 'Y-m-d H:i:s' ) );

		$agent->tokensUsedMonth = 0;
		$agent->budgetResetAt   = $start;

		return $agent;
	}

	/**
	 * The budget as the editor shows it.
	 *
	 * @param Agent $agent The clerk.
	 * @return array<string, mixed>
	 */
	public function describe( Agent $agent ): array {
		$estimate = $this->estimateCost( $agent );

		return array(
			'tokens'          => $agent->tokenBudget,
			'used'            => $agent->tokensUsedMonth,
			'ratio'           => round( $agent->budgetUsedRatio(), 4 ),
			'exhausted'       => $agent->hasExhaustedBudget(),
			'blocking'        => $agent->isBudgetBlocked(),
			'warning'         => null !== $agent->tokenBudget && $agent->budgetUsedRatio() >= self::WARN_AT,
			'on_exhausted'    => $agent->stopsAtBudget() ? 'fallback' : 'continue',
			'resets_at'       => $this->nextReset()->format( 'Y-m-d H:i:s' ),
			// Null, never zero. A model we hold no price for is unknown, and
			// a zero here sums into a spend figure that is wrong in the
			// direction nobody audits.
			'estimated_cost'  => $estimate,
			'estimated_basis' => null === $estimate ? 'unpriced' : 'published_rates',
		);
	}

	/**
	 * What a full budget would cost at published rates, or null.
	 *
	 * Assumes the observed shape of a grounded conversation rather than a
	 * flat split: prompts carry retrieved context and history, replies are
	 * two or three sentences, so input dominates by roughly nine to one.
	 * Stated here because a cost estimate whose assumptions are invisible
	 * is a number an operator cannot argue with.
	 *
	 * @param Agent $agent The clerk.
	 * @return float|null
	 */
	public function estimateCost( Agent $agent ): ?float {
		$budget   = $agent->tokenBudget;
		$provider = $agent->provider();
		$model    = $agent->model();

		if ( null === $budget || 0 === $budget || null === $provider || null === $model ) {
			return null;
		}

		$tokensIn  = (int) round( $budget * 0.9 );
		$tokensOut = $budget - $tokensIn;

		return $this->pricing->cost( $provider, $model, $tokensIn, $tokensOut );
	}

	/**
	 * The first instant of the current month, UTC.
	 *
	 * Calendar months rather than rolling thirty days, because the bill
	 * the customer is comparing it against is a calendar month.
	 *
	 * @param DateTimeImmutable $now Current time.
	 * @return DateTimeImmutable
	 */
	private function periodStart( DateTimeImmutable $now ): DateTimeImmutable {
		return $now->modify( 'first day of this month' )->setTime( 0, 0 );
	}

	/**
	 * When the counter next rolls over.
	 *
	 * @return DateTimeImmutable
	 */
	private function nextReset(): DateTimeImmutable {
		return $this->clock->now()->modify( 'first day of next month' )->setTime( 0, 0 );
	}
}
