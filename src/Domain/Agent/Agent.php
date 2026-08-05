<?php
/**
 * Agent entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Agent;

use DateTimeImmutable;
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
	 * @param string|null           $avatarUrl       Face shown in the widget.
	 * @param array<string, mixed>  $widgetConfig    Position, accent, radius, theme.
	 * @param array<string, mixed>  $personality     Tone dials.
	 * @param array<string, mixed>  $displayRulesRaw Where the clerk appears.
	 * @param array<string, mixed>  $leadConfig      Qualification settings, Sprint 7.
	 * @param DateTimeImmutable|null $budgetResetAt  When the monthly counter last rolled over.
	 * @param DateTimeImmutable|null $createdAt      Hire date, UTC.
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
		public ?string $avatarUrl = null,
		public array $widgetConfig = array(),
		public array $personality = array(),
		public array $displayRulesRaw = array(),
		public array $leadConfig = array(),
		public ?DateTimeImmutable $budgetResetAt = null,
		public ?DateTimeImmutable $createdAt = null,
	) {
	}

	/**
	 * Whether this clerk is serving visitors right now.
	 *
	 * A published clerk whose budget is spent is not serving, unless its
	 * owner explicitly chose to keep answering past the cap: the cap is a
	 * promise about money, and honouring it by default is the whole point
	 * of setting one.
	 *
	 * @return bool
	 */
	public function isServing(): bool {
		return $this->status->isServing() && ! $this->isBudgetBlocked();
	}

	/**
	 * Where this clerk is allowed to appear (FR-CLK-07).
	 *
	 * @return DisplayRules
	 */
	public function displayRules(): DisplayRules {
		return DisplayRules::fromArray( $this->displayRulesRaw );
	}

	/**
	 * Whether this clerk should serve the page described.
	 *
	 * @param PageContext $context The page view.
	 * @return bool
	 */
	public function appearsOn( PageContext $context ): bool {
		return $this->displayRules()->allows( $context );
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
	 * Whether an exhausted budget actually stops this clerk.
	 *
	 * Defaults to stopping. A cap that keeps spending after it is reached
	 * is not a cap, and an operator who typed a number into a field
	 * labelled "monthly token budget" has said what they want.
	 *
	 * @return bool
	 */
	public function stopsAtBudget(): bool {
		return 'continue' !== ( $this->guardrails['on_budget_exhausted'] ?? 'fallback' );
	}

	/**
	 * Whether the budget is both spent and binding.
	 *
	 * @return bool
	 */
	public function isBudgetBlocked(): bool {
		return $this->hasExhaustedBudget() && $this->stopsAtBudget();
	}

	/**
	 * Text appended verbatim to every reply, or null.
	 *
	 * A required disclaimer (FR-CLK-06) is a legal instrument, so it is
	 * appended by us rather than asked of the model. A model instructed to
	 * "always end with X" complies almost always, and "almost" is not a
	 * property anyone wants attached to a VAT notice.
	 *
	 * @return string|null
	 */
	public function disclaimer(): ?string {
		$value = $this->guardrails['disclaimer'] ?? null;

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		return trim( $value );
	}

	/**
	 * How formal the clerk sounds, 0 formal to 1 casual.
	 *
	 * @return float
	 */
	public function formality(): float {
		return $this->dial( 'formality', 0.5 );
	}

	/**
	 * How much the clerk says, 0 brief to 1 detailed.
	 *
	 * @return float
	 */
	public function verbosity(): float {
		return $this->dial( 'verbosity', 0.35 );
	}

	/**
	 * A personality dial, clamped to 0..1.
	 *
	 * @param string $key      Dial name.
	 * @param float  $fallback Value when unset or unreadable.
	 * @return float
	 */
	private function dial( string $key, float $fallback ): float {
		$value = $this->personality[ $key ] ?? null;

		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return max( 0.0, min( 1.0, (float) $value ) );
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

	/**
	 * Provider this clerk is configured to use.
	 *
	 * @return string|null
	 */
	public function provider(): ?string {
		$value = $this->modelConfig['provider'] ?? null;

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Model this clerk is configured to use.
	 *
	 * @return string|null
	 */
	public function model(): ?string {
		$value = $this->modelConfig['model'] ?? null;

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Sampling temperature, clamped to a range every provider accepts.
	 *
	 * Defaults low. A support clerk quoting a returns policy is not a
	 * creative writing task, and the cost of an invented detail is a
	 * customer acting on it.
	 *
	 * @return float
	 */
	public function temperature(): float {
		$value = $this->modelConfig['temperature'] ?? 0.3;

		return is_numeric( $value ) ? max( 0.0, min( 1.0, (float) $value ) ) : 0.3;
	}

	/**
	 * Output ceiling for one reply.
	 *
	 * @return int
	 */
	public function maxTokens(): int {
		$value = $this->modelConfig['max_tokens'] ?? 800;

		return is_numeric( $value ) ? max( 64, min( 4096, (int) $value ) ) : 800;
	}

	/**
	 * How many prior turns are replayed into the prompt.
	 *
	 * Bounded, and bounded low. Every turn carried forward is billed again
	 * on every subsequent message, so an unbounded history turns a long
	 * conversation into a quadratic bill.
	 *
	 * @return int
	 */
	public function historyTurns(): int {
		$value = $this->modelConfig['history_turns'] ?? 8;

		return is_numeric( $value ) ? max( 0, min( 30, (int) $value ) ) : 8;
	}

	/**
	 * How many chunks retrieval hands to the prompt.
	 *
	 * @return int
	 */
	public function topK(): int {
		$value = $this->guardrails['top_k'] ?? 5;

		return is_numeric( $value ) ? max( 1, min( 12, (int) $value ) ) : 5;
	}

	/**
	 * Topics this clerk must refuse to discuss.
	 *
	 * @return array<int, string>
	 */
	public function bannedTopics(): array {
		$value = $this->guardrails['banned_topics'] ?? array();

		if ( ! is_array( $value ) ) {
			return array();
		}

		$topics = array();

		foreach ( $value as $topic ) {
			if ( is_string( $topic ) && '' !== trim( $topic ) ) {
				$topics[] = trim( $topic );
			}
		}

		return $topics;
	}

	/**
	 * What the visitor sees when the clerk cannot or will not answer.
	 *
	 * Never an error. A visitor who hits a token budget did nothing wrong
	 * and has no way to act on the reason, so the message they get is the
	 * one the owner wrote for the case where the clerk falls short.
	 *
	 * @return string
	 */
	public function fallbackText(): string {
		$configured = trim( (string) $this->fallbackMessage );

		if ( '' !== $configured ) {
			return $configured;
		}

		return "I don't have that in what I've been given to read. "
			. 'If you leave your email, someone here will follow it up.';
	}
}
