<?php
/**
 * Fields a condition can read.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

/**
 * The closed set of readable fields, with the context key each maps to.
 *
 * A whitelist rather than a free path expression. The alternative — let
 * the operator type `lead.consent.ip` and read whatever is there — turns
 * the condition box into a way to test values the screen never showed
 * anyone, and makes every future change to the context shape a breaking
 * change to somebody's saved workflow.
 *
 * `Answer` is the one field that takes a key, because qualification
 * questions are defined per clerk and cannot be enumerated here.
 */
enum ConditionField: string {

	case Score            = 'score';
	case Band             = 'band';
	case Stage            = 'stage';
	case Status           = 'status';
	case Source           = 'source';
	case Email            = 'email';
	case Phone            = 'phone';
	case Company          = 'company';
	case DaysSinceCreated = 'days_since_created';
	case Answer           = 'answer';

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Score            => 'Score',
			self::Band             => 'Score band',
			self::Stage            => 'Pipeline stage',
			self::Status           => 'Status',
			self::Source           => 'Captured by',
			self::Email            => 'Email address',
			self::Phone            => 'Phone number',
			self::Company          => 'Company',
			self::DaysSinceCreated => 'Days since captured',
			self::Answer           => 'Answer to a question',
		};
	}

	/**
	 * The context key this field reads.
	 *
	 * @return string
	 */
	public function key(): string {
		return match ( $this ) {
			self::Score            => 'lead.score',
			self::Band             => 'lead.band',
			self::Stage            => 'lead.stage_id',
			self::Status           => 'lead.status',
			self::Source           => 'lead.source',
			self::Email            => 'lead.email',
			self::Phone            => 'lead.phone',
			self::Company          => 'lead.company',
			self::DaysSinceCreated => 'lead.days_since_created',
			self::Answer           => 'lead.answers',
		};
	}

	/**
	 * Whether comparisons on this field are numeric.
	 *
	 * @return bool
	 */
	public function isNumeric(): bool {
		return match ( $this ) {
			self::Score, self::DaysSinceCreated => true,
			default                             => false,
		};
	}

	/**
	 * Whether the field needs a question key alongside it.
	 *
	 * @return bool
	 */
	public function needsKey(): bool {
		return self::Answer === $this;
	}

	/**
	 * Read a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self|null Null when the value names no known field.
	 */
	public static function tryFromStorage( ?string $value ): ?self {
		return null === $value ? null : self::tryFrom( $value );
	}
}
