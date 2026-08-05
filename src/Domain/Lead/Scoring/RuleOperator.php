<?php
/**
 * Scoring rule operator.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead\Scoring;

/**
 * How a rule compares what it found with what it wanted.
 *
 * A closed vocabulary. Everything here is decidable by reading this
 * file, which is what makes it possible to say that a customer-supplied
 * scoring rule cannot execute anything.
 */
enum RuleOperator: string {

	case NotEmpty       = 'not_empty';
	case IsEmpty        = 'is_empty';
	case Equals         = 'equals';
	case NotEquals      = 'not_equals';
	case Contains       = 'contains';
	case Matches        = 'matches';
	case GreaterOrEqual = 'gte';
	case LessOrEqual    = 'lte';
	case IsBusiness     = 'is_business';

	/**
	 * Whether this operator needs a value to compare against.
	 *
	 * @return bool
	 */
	public function needsValue(): bool {
		return match ( $this ) {
			self::NotEmpty, self::IsEmpty, self::IsBusiness => false,
			default => true,
		};
	}

	/**
	 * Whether the comparison is numeric.
	 *
	 * @return bool
	 */
	public function isNumeric(): bool {
		return self::GreaterOrEqual === $this || self::LessOrEqual === $this;
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::NotEmpty       => 'is answered',
			self::IsEmpty        => 'is blank',
			self::Equals         => 'is',
			self::NotEquals      => 'is not',
			self::Contains       => 'contains',
			self::Matches        => 'matches',
			self::GreaterOrEqual => 'is at least',
			self::LessOrEqual    => 'is at most',
			self::IsBusiness     => 'is a business address',
		};
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self|null
	 */
	public static function fromStorage( ?string $value ): ?self {
		return self::tryFrom( (string) $value );
	}
}
