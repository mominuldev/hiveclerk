<?php
/**
 * Comparison operators for a condition node.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * How a field is compared with a value.
 *
 * `IsSet` and `IsEmpty` take no value, and the builder hides the value
 * input for them. That is not cosmetic: "company is empty" with a stray
 * value left in the box is a condition an operator reads as one thing and
 * the engine reads as another.
 */
enum ConditionOperator: string {

	case Equals      = 'equals';
	case NotEquals   = 'not_equals';
	case GreaterThan = 'greater_than';
	case LessThan    = 'less_than';
	case Contains    = 'contains';
	case NotContains = 'not_contains';
	case IsSet       = 'is_set';
	case IsEmpty     = 'is_empty';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Equals      => 'is',
			self::NotEquals   => 'is not',
			self::GreaterThan => 'is more than',
			self::LessThan    => 'is less than',
			self::Contains    => 'contains',
			self::NotContains => 'does not contain',
			self::IsSet       => 'is filled in',
			self::IsEmpty     => 'is empty',
		};
	}

	/**
	 * Whether the operator compares against a value at all.
	 *
	 * @return bool
	 */
	public function needsValue(): bool {
		return match ( $this ) {
			self::IsSet, self::IsEmpty => false,
			default                    => true,
		};
	}

	/**
	 * Whether the operator only makes sense on a number.
	 *
	 * @return bool
	 */
	public function isNumeric(): bool {
		return match ( $this ) {
			self::GreaterThan, self::LessThan => true,
			default                           => false,
		};
	}

	/**
	 * Read a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self|null Null when the value names no known operator.
	 */
	public static function tryFromStorage( ?string $value ): ?self {
		return null === $value ? null : self::tryFrom( $value );
	}
}
