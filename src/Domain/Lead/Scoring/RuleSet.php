<?php
/**
 * The customer's scoring policy.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead\Scoring;

/**
 * Every rule, in order, with the evaluation that turns them into points.
 *
 * The whole engine is here and it imports nothing. That is what makes
 * "a customer's scoring rules cannot execute anything" a claim you can
 * check by reading two files rather than by trusting a sanitiser.
 */
final readonly class RuleSet {

	/**
	 * Most rules one site may hold.
	 *
	 * Every enabled rule is evaluated after every visitor message. Sixty
	 * is far past any policy anyone has described and cheap enough that
	 * the whole pass stays under a millisecond.
	 */
	public const MAX_RULES = 60;

	/**
	 * Construct.
	 *
	 * @param array<int, ScoringRule> $rules Rules, in editor order.
	 */
	public function __construct( public array $rules = array() ) {
	}

	/**
	 * Build from stored configuration.
	 *
	 * @param array<mixed> $stored Stored rules.
	 * @return self
	 */
	public static function fromArray( array $stored ): self {
		$rules = array();
		$seen  = array();

		foreach ( $stored as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$rule = ScoringRule::fromArray( $entry );

			if ( null === $rule || isset( $seen[ $rule->id ] ) ) {
				// A duplicate id would award twice and, worse, would make
				// "award this once" mean "award this twice" — the two
				// rules share the key the once-check is keyed on.
				continue;
			}

			$seen[ $rule->id ] = true;
			$rules[]           = $rule;

			if ( count( $rules ) >= self::MAX_RULES ) {
				break;
			}
		}

		return new self( $rules );
	}

	/**
	 * The rules that fire, minus the ones already paid out.
	 *
	 * @param ScoreSignals       $signals  What is known.
	 * @param array<int, string> $awarded  Rule ids this lead has already been scored on.
	 * @return array<int, ScoringRule>
	 */
	public function evaluate( ScoreSignals $signals, array $awarded = array() ): array {
		$paid    = array_flip( $awarded );
		$matched = array();

		foreach ( $this->rules as $rule ) {
			if ( $rule->once && isset( $paid[ $rule->id ] ) ) {
				continue;
			}

			if ( $rule->matches( $signals ) ) {
				$matched[] = $rule;
			}
		}

		return $matched;
	}

	/**
	 * The highest total this policy can produce.
	 *
	 * Shown next to the score so "72" has a denominator. A score with no
	 * ceiling is a number a salesperson cannot read: 72 out of 90 and 72
	 * out of 400 are different leads.
	 *
	 * @return int
	 */
	public function ceiling(): int {
		$total = 0;

		foreach ( $this->rules as $rule ) {
			if ( $rule->enabled && $rule->points > 0 ) {
				$total += $rule->points;
			}
		}

		return $total;
	}

	/**
	 * Storage form.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function toArray(): array {
		return array_map(
			static fn ( ScoringRule $rule ): array => $rule->toArray(),
			$this->rules
		);
	}

	/**
	 * Whether anything has been configured.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return array() === $this->rules;
	}

	/**
	 * The policy a site starts with.
	 *
	 * Seeded rather than empty, and seeded enabled. An unconfigured
	 * scoring engine scores every lead zero, and a pipeline of identical
	 * cold cards is not a neutral starting point — it is the feature
	 * appearing broken. These seven are visible on the Rules screen from
	 * the first day and every one of them is editable.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function defaults(): array {
		return array(
			array(
				'id'       => 'business_email',
				'label'    => 'Gave a business email address',
				'kind'     => RuleKind::Field->value,
				'operator' => RuleOperator::IsBusiness->value,
				'target'   => 'email',
				'value'    => '',
				'points'   => 15,
			),
			array(
				'id'       => 'phone_given',
				'label'    => 'Left a phone number',
				'kind'     => RuleKind::Field->value,
				'operator' => RuleOperator::NotEmpty->value,
				'target'   => 'phone',
				'value'    => '',
				'points'   => 10,
			),
			array(
				'id'       => 'company_given',
				'label'    => 'Named their company',
				'kind'     => RuleKind::Field->value,
				'operator' => RuleOperator::NotEmpty->value,
				'target'   => 'company',
				'value'    => '',
				'points'   => 8,
			),
			array(
				'id'       => 'pricing_repeat',
				'label'    => 'Visited pricing twice or more',
				'kind'     => RuleKind::Page->value,
				'operator' => RuleOperator::GreaterOrEqual->value,
				'target'   => '/pricing*',
				'value'    => '2',
				'points'   => 20,
			),
			array(
				'id'       => 'buying_language',
				'label'    => 'Used buying language',
				'kind'     => RuleKind::Keyword->value,
				'operator' => RuleOperator::Contains->value,
				'target'   => '',
				'value'    => 'quote, pricing, demo, trial, contract, invoice, purchase order, budget',
				'points'   => 12,
			),
			array(
				'id'       => 'sustained_conversation',
				'label'    => 'Stayed for a long conversation',
				'kind'     => RuleKind::Engagement->value,
				'operator' => RuleOperator::GreaterOrEqual->value,
				'target'   => 'messages',
				'value'    => '6',
				'points'   => 10,
			),
			array(
				'id'       => 'answered_questions',
				'label'    => 'Answered the qualifying questions',
				'kind'     => RuleKind::Engagement->value,
				'operator' => RuleOperator::GreaterOrEqual->value,
				'target'   => 'answers',
				'value'    => '2',
				'points'   => 15,
			),
		);
	}
}
