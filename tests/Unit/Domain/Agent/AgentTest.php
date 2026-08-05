<?php
/**
 * Agent domain tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Domain\Agent;

use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentStatus;
use Hiveclerk\Domain\Shared\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass( Agent::class )]
#[CoversClass( AgentStatus::class )]
final class AgentTest extends TestCase {

	/**
	 * Build a clerk for testing.
	 *
	 * @param AgentStatus          $status     Lifecycle state.
	 * @param int|null             $budget     Token budget.
	 * @param int                  $used       Tokens consumed.
	 * @param array<string, mixed> $guardrails Guardrail config.
	 * @return Agent
	 */
	private function agent(
		AgentStatus $status = AgentStatus::Published,
		?int $budget = null,
		int $used = 0,
		array $guardrails = array()
	): Agent {
		return new Agent(
			id: 1,
			uuid: Uuid::generate(),
			name: 'Ada',
			slug: 'ada',
			status: $status,
			guardrails: $guardrails,
			tokenBudget: $budget,
			tokensUsedMonth: $used,
		);
	}

	public function testPublishedClerkWithoutABudgetIsServing(): void {
		$this->assertTrue( $this->agent()->isServing() );
	}

	public function testDraftClerkIsNotServing(): void {
		$this->assertFalse( $this->agent( AgentStatus::Draft )->isServing() );
	}

	public function testPausedClerkIsNotServing(): void {
		$this->assertFalse( $this->agent( AgentStatus::Paused )->isServing() );
	}

	/**
	 * The SEC-03 cost-exhaustion guard, enforced in the domain rather than
	 * left to a caller to remember.
	 */
	public function testPublishedClerkOverBudgetStopsServing(): void {
		$agent = $this->agent( AgentStatus::Published, 1000, 1000 );

		$this->assertTrue( $agent->hasExhaustedBudget() );
		$this->assertFalse(
			$agent->isServing(),
			'A clerk that has spent its budget must stop, not keep billing the owner.'
		);
	}

	public function testClerkUnderBudgetKeepsServing(): void {
		$agent = $this->agent( AgentStatus::Published, 1000, 999 );

		$this->assertFalse( $agent->hasExhaustedBudget() );
		$this->assertTrue( $agent->isServing() );
	}

	public function testNullBudgetMeansUnlimited(): void {
		$agent = $this->agent( AgentStatus::Published, null, 10_000_000 );

		$this->assertFalse( $agent->hasExhaustedBudget() );
		$this->assertSame( 0.0, $agent->budgetUsedRatio() );
	}

	public function testBudgetRatioIsCappedAtOne(): void {
		$agent = $this->agent( AgentStatus::Published, 100, 250 );

		$this->assertSame( 1.0, $agent->budgetUsedRatio() );
	}

	public function testBudgetRatioIsProportional(): void {
		$agent = $this->agent( AgentStatus::Published, 1000, 250 );

		$this->assertSame( 0.25, $agent->budgetUsedRatio() );
	}

	public function testZeroBudgetDoesNotDivideByZero(): void {
		$agent = $this->agent( AgentStatus::Published, 0, 0 );

		$this->assertSame( 0.0, $agent->budgetUsedRatio() );
	}

	/**
	 * The safe behaviour has to be the default. A clerk that invents a price
	 * does more commercial damage than one that declines to answer.
	 */
	public function testRefusesToInventByDefault(): void {
		$this->assertTrue( $this->agent()->refusesToInvent() );
	}

	public function testInventionCanBeExplicitlyAllowed(): void {
		$agent = $this->agent( guardrails: array( 'no_invent_facts' => false ) );

		$this->assertFalse( $agent->refusesToInvent() );
	}

	public function testConfidenceThresholdHasASensibleDefault(): void {
		$this->assertSame( 0.62, $this->agent()->confidenceThreshold() );
	}

	public function testConfidenceThresholdIsConfigurable(): void {
		$agent = $this->agent( guardrails: array( 'confidence_threshold' => 0.8 ) );

		$this->assertSame( 0.8, $agent->confidenceThreshold() );
	}

	public function testMalformedThresholdFallsBackToTheDefault(): void {
		$agent = $this->agent( guardrails: array( 'confidence_threshold' => 'nonsense' ) );

		$this->assertSame( 0.62, $agent->confidenceThreshold() );
	}

	public function testUnknownStoredStatusFallsBackToDraft(): void {
		$this->assertSame( AgentStatus::Draft, AgentStatus::fromStorage( 'nonsense' ) );
		$this->assertSame( AgentStatus::Draft, AgentStatus::fromStorage( null ) );
	}
}
