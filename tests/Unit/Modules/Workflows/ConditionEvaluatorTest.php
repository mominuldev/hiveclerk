<?php
/**
 * Condition evaluation tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Workflows;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Domain\Workflow\ConditionField;
use Hiveclerk\Domain\Workflow\ConditionOperator;
use Hiveclerk\Domain\Workflow\NodeType;
use Hiveclerk\Domain\Workflow\WorkflowContext;
use Hiveclerk\Domain\Workflow\WorkflowNode;
use Hiveclerk\Modules\Workflows\Services\ConditionEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a branch actually decides.
 *
 * The tests worth having here are the ones about absence. A lead with no
 * score row is not a lead with a score of zero, and "score is less than
 * 10" matching them is how a nurture sequence reaches somebody the system
 * has never assessed. Likewise a condition that cannot be parsed takes
 * the No branch rather than throwing: a run that stops mid-way has
 * already done half its actions, and the conservative branch has done
 * none of the ones the operator was worried about.
 *
 * @internal
 */
#[CoversClass( ConditionEvaluator::class )]
final class ConditionEvaluatorTest extends TestCase {

	private ConditionEvaluator $evaluator;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();

		$this->evaluator = new ConditionEvaluator();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testANumberOverTheThresholdMatches(): void {
		$verdict = $this->evaluate(
			ConditionField::Score,
			ConditionOperator::GreaterThan,
			'60',
			array( 'lead.score' => 75 )
		);

		self::assertTrue( $verdict['matched'] );
	}

	public function testANumberUnderTheThresholdDoesNot(): void {
		$verdict = $this->evaluate(
			ConditionField::Score,
			ConditionOperator::GreaterThan,
			'60',
			array( 'lead.score' => 45 )
		);

		self::assertFalse( $verdict['matched'] );
	}

	public function testAMissingNumberIsNotZero(): void {
		// The failure this prevents: "score is less than 10" matching a
		// lead who has never been scored, and emailing them as if they had
		// been assessed and found wanting.
		$verdict = $this->evaluate(
			ConditionField::Score,
			ConditionOperator::LessThan,
			'10',
			array()
		);

		self::assertFalse( $verdict['matched'] );
	}

	public function testTextComparisonIgnoresCaseAndSurroundingSpace(): void {
		$verdict = $this->evaluate(
			ConditionField::Company,
			ConditionOperator::Equals,
			'Acme Ltd',
			array( 'lead.company' => '  acme ltd ' )
		);

		self::assertTrue( $verdict['matched'] );
	}

	public function testIsSetIsFalseForAnEmptyString(): void {
		$verdict = $this->evaluate(
			ConditionField::Phone,
			ConditionOperator::IsSet,
			null,
			array( 'lead.phone' => '   ' )
		);

		self::assertFalse( $verdict['matched'] );
	}

	public function testIsEmptyIsTrueWhenTheFieldWasNeverSet(): void {
		$verdict = $this->evaluate(
			ConditionField::Phone,
			ConditionOperator::IsEmpty,
			null,
			array()
		);

		self::assertTrue( $verdict['matched'] );
	}

	public function testAnAnswerToAQualificationQuestionIsRead(): void {
		$node = new WorkflowNode(
			'check',
			NodeType::Condition,
			array(
				'field'    => ConditionField::Answer->value,
				'operator' => ConditionOperator::Contains->value,
				'value'    => 'enterprise',
				'key'      => 'plan',
			)
		);

		$verdict = $this->evaluator->evaluate(
			$node,
			new WorkflowContext( array( 'lead.answers' => array( 'plan' => 'Enterprise tier' ) ) )
		);

		self::assertTrue( $verdict['matched'] );
	}

	public function testAConditionThatCannotBeReadTakesTheNoBranch(): void {
		$node = new WorkflowNode(
			'check',
			NodeType::Condition,
			array(
				'field'    => 'invented_field',
				'operator' => 'sideways',
			)
		);

		$verdict = $this->evaluator->evaluate( $node, new WorkflowContext() );

		self::assertFalse( $verdict['matched'] );
	}

	public function testTheLogRecordsTheValueItActuallyCompared(): void {
		// "Score is more than 60 → No" tells an operator nothing they did
		// not already fear. "Score was 45" ends the investigation.
		$verdict = $this->evaluate(
			ConditionField::Score,
			ConditionOperator::GreaterThan,
			'60',
			array( 'lead.score' => 45 )
		);

		self::assertStringContainsString( '45', $verdict['detail'] );
	}

	public function testTheLogSaysNothingRatherThanShowingABlank(): void {
		$verdict = $this->evaluate(
			ConditionField::Company,
			ConditionOperator::Equals,
			'Acme',
			array()
		);

		self::assertStringContainsString( 'nothing', $verdict['detail'] );
	}

	/**
	 * Evaluate one condition.
	 *
	 * @param ConditionField       $field    Field.
	 * @param ConditionOperator    $operator Operator.
	 * @param string|null          $value    Expected value.
	 * @param array<string, mixed> $context  Context values.
	 * @return array{matched: bool, detail: string}
	 */
	private function evaluate(
		ConditionField $field,
		ConditionOperator $operator,
		?string $value,
		array $context
	): array {
		$config = array(
			'field'    => $field->value,
			'operator' => $operator->value,
		);

		if ( null !== $value ) {
			$config['value'] = $value;
		}

		return $this->evaluator->evaluate(
			new WorkflowNode( 'check', NodeType::Condition, $config ),
			new WorkflowContext( $context )
		);
	}
}
