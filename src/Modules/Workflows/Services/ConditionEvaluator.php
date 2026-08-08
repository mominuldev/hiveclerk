<?php
/**
 * Condition evaluation.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Services;

use Hiveclerk\Domain\Workflow\ConditionField;
use Hiveclerk\Domain\Workflow\ConditionOperator;
use Hiveclerk\Domain\Workflow\WorkflowContext;
use Hiveclerk\Domain\Workflow\WorkflowNode;

/**
 * Answers one condition node, and says what it compared (FR-WFL-02).
 *
 * ## The detail line is not decoration
 *
 * Every evaluation returns the value it actually read. "Score is more
 * than 60 → No" tells an operator nothing they did not already fear;
 * "Score was 45 → No" ends the investigation. The run log is the only
 * window into a decision made at four in the morning against a lead
 * whose score has since changed, and a log that only records the verdict
 * makes every support question unanswerable.
 *
 * ## An unreadable condition is false, and says so
 *
 * A node whose field or operator no longer parses — a graph written by a
 * newer version, a hand-edited row — takes the "no" branch rather than
 * throwing. A run that stops mid-way has already done half its actions;
 * one that takes the conservative branch has done none of the ones behind
 * the yes edge, which is the failure an operator can undo.
 */
final class ConditionEvaluator {

	/**
	 * Evaluate one condition node.
	 *
	 * @param WorkflowNode    $node    Condition node.
	 * @param WorkflowContext $context What the run knows.
	 * @return array{matched: bool, detail: string}
	 */
	public function evaluate( WorkflowNode $node, WorkflowContext $context ): array {
		$field    = ConditionField::tryFromStorage( $node->string( 'field' ) );
		$operator = ConditionOperator::tryFromStorage( $node->string( 'operator' ) );

		if ( null === $field || null === $operator ) {
			return array(
				'matched' => false,
				'detail'  => __( 'This condition could not be read, so the No branch was taken.', 'hiveclerk' ),
			);
		}

		$actual   = $this->read( $field, $node, $context );
		$expected = $node->string( 'value' );
		$matched  = $this->compare( $operator, $actual, $expected );

		return array(
			'matched' => $matched,
			'detail'  => $this->explain( $field, $operator, $actual, $expected, $matched ),
		);
	}

	/**
	 * The value a field currently holds.
	 *
	 * @param ConditionField  $field   Field.
	 * @param WorkflowNode    $node    Node, for the answer key.
	 * @param WorkflowContext $context What the run knows.
	 * @return string|int|null
	 */
	private function read( ConditionField $field, WorkflowNode $node, WorkflowContext $context ): string|int|null {
		if ( ! $field->needsKey() ) {
			return $field->isNumeric()
				? $context->int( $field->key() )
				: $context->string( $field->key() );
		}

		$key = $node->string( 'key' );

		if ( null === $key ) {
			return null;
		}

		$answers = $context->get( $field->key() );

		if ( ! is_array( $answers ) ) {
			return null;
		}

		$value = $answers[ $key ] ?? null;

		if ( is_string( $value ) || is_int( $value ) ) {
			return $value;
		}

		return is_float( $value ) ? (int) $value : null;
	}

	/**
	 * Apply the operator.
	 *
	 * @param ConditionOperator $operator Operator.
	 * @param string|int|null   $actual   What the field holds.
	 * @param string|null       $expected What the node was configured with.
	 * @return bool
	 */
	private function compare( ConditionOperator $operator, string|int|null $actual, ?string $expected ): bool {
		$present = null !== $actual && '' !== trim( (string) $actual );

		return match ( $operator ) {
			ConditionOperator::IsSet   => $present,
			ConditionOperator::IsEmpty => ! $present,
			// A comparison against nothing is not a comparison. Rather
			// than coercing null to "" and matching every empty field,
			// an unset expectation fails — the graph validator has
			// already refused to activate a node in this state, so
			// reaching here means the row was edited around it.
			default                    => null === $expected
				? false
				: $this->compareValues( $operator, $actual, $expected ),
		};
	}

	/**
	 * Compare two present values.
	 *
	 * @param ConditionOperator $operator Operator.
	 * @param string|int|null   $actual   What the field holds.
	 * @param string            $expected What the node was configured with.
	 * @return bool
	 */
	private function compareValues( ConditionOperator $operator, string|int|null $actual, string $expected ): bool {
		if ( $operator->isNumeric() ) {
			// A missing number is not zero. "Score is less than 10" must
			// not match a lead with no score row at all, which is exactly
			// the comparison that would send a nurture email to somebody
			// the system has never scored.
			if ( ! is_numeric( $actual ) || ! is_numeric( $expected ) ) {
				return false;
			}

			$left  = (float) $actual;
			$right = (float) $expected;

			return ConditionOperator::GreaterThan === $operator ? $left > $right : $left < $right;
		}

		$left  = strtolower( trim( (string) ( $actual ?? '' ) ) );
		$right = strtolower( trim( $expected ) );

		return match ( $operator ) {
			ConditionOperator::Equals      => $left === $right,
			ConditionOperator::NotEquals   => $left !== $right,
			ConditionOperator::Contains    => '' !== $right && str_contains( $left, $right ),
			ConditionOperator::NotContains => '' === $right || ! str_contains( $left, $right ),
			default                        => false,
		};
	}

	/**
	 * The line the run log keeps.
	 *
	 * @param ConditionField    $field    Field.
	 * @param ConditionOperator $operator Operator.
	 * @param string|int|null   $actual   What the field held.
	 * @param string|null       $expected What was expected.
	 * @param bool              $matched  The verdict.
	 * @return string
	 */
	private function explain(
		ConditionField $field,
		ConditionOperator $operator,
		string|int|null $actual,
		?string $expected,
		bool $matched
	): string {
		$seen = null === $actual || '' === trim( (string) $actual )
			? __( 'nothing', 'hiveclerk' )
			: (string) $actual;

		if ( ! $operator->needsValue() ) {
			return sprintf(
				/* translators: 1: field name, 2: the value found, 3: yes or no. */
				__( '%1$s was %2$s → %3$s', 'hiveclerk' ),
				$field->label(),
				$seen,
				$matched ? __( 'Yes', 'hiveclerk' ) : __( 'No', 'hiveclerk' )
			);
		}

		return sprintf(
			/* translators: 1: field name, 2: the value found, 3: operator, 4: expected value, 5: yes or no. */
			__( '%1$s was %2$s, needed %3$s %4$s → %5$s', 'hiveclerk' ),
			$field->label(),
			$seen,
			$operator->label(),
			(string) $expected,
			$matched ? __( 'Yes', 'hiveclerk' ) : __( 'No', 'hiveclerk' )
		);
	}
}
