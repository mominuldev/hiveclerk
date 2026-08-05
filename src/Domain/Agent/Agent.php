<?php
/**
 * Agent entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Agent;

use Hiveclerk\Domain\Shared\Uuid;

/**
 * A clerk: a configured AI worker with a job description and a budget.
 *
 * This class imports nothing from WordPress. That constraint is enforced in
 * CI by the hiveclerk.domainPurity PHPStan rule, and it is what makes the
 * service layer portable to the hosted product later.
 */
final class Agent {

	/**
	 * Construct.
	 *
	 * @param int|null              $id              Storage id, null before first save.
	 * @param Uuid                  $uuid            Public identifier.
	 * @param string                $name            Display name.
	 * @param string                $slug            URL-safe name.
	 * @param string                $rolePreset      Role preset key.
	 * @param AgentStatus           $status          Lifecycle state.
	 * @param string|null           $greeting        Opening message.
	 * @param string|null           $fallbackMessage Shown when the clerk cannot answer.
	 * @param string|null           $instructions    The job description.
	 * @param array<string, mixed>  $modelConfig     Provider, model, temperature.
	 * @param array<string, mixed>  $guardrails      Limits the clerk must respect.
	 * @param int|null              $tokenBudget     Monthly cap, null for unlimited.
	 * @param int                   $tokensUsedMonth Consumed this period.
	 */
	public function __construct(
		public ?int $id,
		public Uuid $uuid,
		public string $name,
		public string $slug,
		public string $rolePreset = 'support',
		public AgentStatus $status = AgentStatus::Draft,
		public ?string $greeting = null,
		public ?string $fallbackMessage = null,
		public ?string $instructions = null,
		public array $modelConfig = array(),
		public array $guardrails = array(),
		public ?int $tokenBudget = null,
		public int $tokensUsedMonth = 0,
	) {
	}

	/**
	 * Whether this clerk is serving visitors right now.
	 *
	 * A published clerk that has exhausted its budget is not serving: it
	 * shows its fallback message instead of spending money the owner did
	 * not agree to.
	 *
	 * @return bool
	 */
	public function isServing(): bool {
		return $this->status->isServing() && ! $this->hasExhaustedBudget();
	}

	/**
	 * Whether the monthly token budget is spent.
	 *
	 * @return bool
	 */
	public function hasExhaustedBudget(): bool {
		if ( null === $this->tokenBudget ) {
			return false;
		}

		return $this->tokensUsedMonth >= $this->tokenBudget;
	}

	/**
	 * Fraction of the budget consumed, 0.0 to 1.0.
	 *
	 * @return float
	 */
	public function budgetUsedRatio(): float {
		if ( null === $this->tokenBudget || 0 === $this->tokenBudget ) {
			return 0.0;
		}

		return min( 1.0, $this->tokensUsedMonth / $this->tokenBudget );
	}

	/**
	 * Whether the clerk refuses to state facts it cannot ground in a source.
	 *
	 * Defaults to true: a clerk that invents a price does more commercial
	 * damage than one that declines, so the safe behaviour is the default
	 * rather than an opt-in.
	 *
	 * @return bool
	 */
	public function refusesToInvent(): bool {
		return (bool) ( $this->guardrails['no_invent_facts'] ?? true );
	}

	/**
	 * Retrieval score below which the clerk hands off instead of answering.
	 *
	 * @return float
	 */
	public function confidenceThreshold(): float {
		$value = $this->guardrails['confidence_threshold'] ?? 0.62;

		return is_numeric( $value ) ? (float) $value : 0.62;
	}
}
