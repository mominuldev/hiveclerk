<?php
/**
 * Scoring rule tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Domain\Lead;

use Hiveclerk\Domain\Lead\Scoring\RuleSet;
use Hiveclerk\Domain\Lead\Scoring\ScoreSignals;
use Hiveclerk\Domain\Lead\Scoring\ScoringRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The rule engine, which imports nothing.
 *
 * These run without Brain Monkey because there is nothing to stub: the
 * whole engine is domain code, which is the claim this file exists to
 * keep true. If a WordPress function ever appears in `RuleSet` or
 * `ScoringRule`, this test fails before the domain-purity rule gets a
 * chance to.
 *
 * @internal
 */
#[CoversClass( RuleSet::class )]
#[CoversClass( ScoringRule::class )]
final class RuleSetTest extends TestCase {

	/**
	 * Build a rule set from partial definitions.
	 *
	 * @param array<int, array<string, mixed>> $rules Rules.
	 * @return RuleSet
	 */
	private function rules( array $rules ): RuleSet {
		return RuleSet::fromArray(
			array_map(
				static fn ( array $rule ): array => array_merge(
					array(
						'label'  => 'A rule',
						'kind'   => 'field',
						'points' => 10,
					),
					$rule
				),
				$rules
			)
		);
	}

	public function testAFieldRuleFiresOnAValueThatIsPresent(): void {
		$set = $this->rules(
			array(
				array(
					'id'       => 'phone',
					'target'   => 'phone',
					'operator' => 'not_empty',
				),
			)
		);

		$matched = $set->evaluate( new ScoreSignals( fields: array( 'phone' => '+49 30 1234567' ) ) );

		self::assertCount( 1, $matched );
		self::assertSame( 'phone', $matched[0]->id );
	}

	public function testABusinessAddressScoresAndAFreeOneDoesNot(): void {
		$set = $this->rules(
			array(
				array(
					'id'       => 'business',
					'target'   => 'email',
					'operator' => 'is_business',
				),
			)
		);

		self::assertCount(
			1,
			$set->evaluate( new ScoreSignals( fields: array( 'email' => 'sarah@nordwind.de' ) ) )
		);

		self::assertCount(
			0,
			$set->evaluate( new ScoreSignals( fields: array( 'email' => 'sarah@gmail.com' ) ) )
		);
	}

	public function testAKeywordMatchesOnWordBoundariesOnly(): void {
		$set = $this->rules(
			array(
				array(
					'id'    => 'intent',
					'kind'  => 'keyword',
					'value' => 'quote, demo',
				),
			)
		);

		self::assertCount(
			1,
			$set->evaluate( new ScoreSignals( transcript: 'can i get a quote for 40 units?' ) )
		);

		// "demo" inside "democracy" is not a buying signal, and a rule that
		// fired on it would score every lead the same.
		self::assertCount(
			0,
			$set->evaluate( new ScoreSignals( transcript: 'i am writing about democracy' ) )
		);
	}

	public function testAPageRuleCountsMatchingPathsTogether(): void {
		$set = $this->rules(
			array(
				array(
					'id'     => 'pricing',
					'kind'   => 'page',
					'target' => '/pricing*',
					'value'  => '2',
				),
			)
		);

		self::assertCount(
			0,
			$set->evaluate( new ScoreSignals( pages: array( '/pricing' => 1 ) ) )
		);

		self::assertCount(
			1,
			$set->evaluate(
				new ScoreSignals(
					pages: array(
						'/pricing'       => 1,
						'/pricing/teams' => 1,
					)
				)
			)
		);
	}

	public function testACurrencyAnswerIsReadAsANumber(): void {
		$set = $this->rules(
			array(
				array(
					'id'       => 'budget',
					'target'   => 'custom.budget',
					'operator' => 'gte',
					'value'    => '5000',
				),
			)
		);

		// The visitor typed a range with a thousands separator, which is
		// what a person types. A rule that could not read it would never
		// fire and the operator would have no way to tell why.
		self::assertCount(
			1,
			$set->evaluate( new ScoreSignals( answers: array( 'budget' => '€5,000 – €15,000' ) ) )
		);

		self::assertCount(
			0,
			$set->evaluate( new ScoreSignals( answers: array( 'budget' => 'under €900' ) ) )
		);
	}

	public function testARuleAlreadyAwardedDoesNotFireAgain(): void {
		$set = $this->rules(
			array(
				array(
					'id'       => 'phone',
					'target'   => 'phone',
					'operator' => 'not_empty',
				),
			)
		);

		$signals = new ScoreSignals( fields: array( 'phone' => '+49 30 1234567' ) );

		self::assertCount( 1, $set->evaluate( $signals ) );
		self::assertCount( 0, $set->evaluate( $signals, array( 'phone' ) ) );
	}

	public function testARepeatableRuleFiresEveryPass(): void {
		$set = $this->rules(
			array(
				array(
					'id'       => 'long',
					'kind'     => 'engagement',
					'target'   => 'messages',
					'operator' => 'gte',
					'value'    => '3',
					'once'     => false,
				),
			)
		);

		$signals = new ScoreSignals( metrics: array( 'messages' => 6.0 ) );

		self::assertCount( 1, $set->evaluate( $signals, array( 'long' ) ) );
	}

	public function testARuleWorthNothingIsDiscarded(): void {
		// Zero points is not a disabled rule, it is a breakdown line that
		// says something happened and awarded nothing.
		$set = $this->rules(
			array(
				array(
					'id'     => 'nothing',
					'points' => 0,
				),
			)
		);

		self::assertTrue( $set->isEmpty() );
	}

	public function testDuplicateIdsAreCollapsed(): void {
		// Two rules sharing an id would make "award once" mean "award
		// twice", because the once-check is keyed on that id.
		$set = $this->rules(
			array(
				array(
					'id'       => 'same',
					'target'   => 'email',
					'operator' => 'not_empty',
				),
				array(
					'id'       => 'same',
					'target'   => 'phone',
					'operator' => 'not_empty',
				),
			)
		);

		self::assertCount( 1, $set->rules );
	}

	public function testTheCeilingCountsOnlyEnabledPositiveRules(): void {
		$set = $this->rules(
			array(
				array(
					'id'     => 'a',
					'points' => 15,
				),
				array(
					'id'     => 'b',
					'points' => 25,
				),
				array(
					'id'     => 'c',
					'points' => -10,
				),
				array(
					'id'      => 'd',
					'points'  => 40,
					'enabled' => false,
				),
			)
		);

		self::assertSame( 40, $set->ceiling() );
	}

	public function testTheDefaultPolicyParsesAndScores(): void {
		$set = RuleSet::fromArray( RuleSet::defaults() );

		self::assertCount( 7, $set->rules );
		self::assertGreaterThan( 0, $set->ceiling() );

		$matched = $set->evaluate(
			new ScoreSignals(
				fields: array(
					'email' => 'j.okafor@trailhead.co',
					'phone' => '+44 20 7946 0958',
				),
				transcript: 'we need a quote before the end of the quarter',
				pages: array( '/pricing' => 3 ),
				metrics: array( 'messages' => 8.0 ),
			)
		);

		$ids = array_map( static fn ( ScoringRule $rule ): string => $rule->id, $matched );

		self::assertContains( 'business_email', $ids );
		self::assertContains( 'phone_given', $ids );
		self::assertContains( 'pricing_repeat', $ids );
		self::assertContains( 'buying_language', $ids );
		self::assertContains( 'sustained_conversation', $ids );
		self::assertNotContains( 'company_given', $ids );
	}
}
