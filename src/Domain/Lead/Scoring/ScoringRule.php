<?php
/**
 * A single scoring rule.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead\Scoring;

/**
 * One line of the customer's scoring policy (FR-LED-03).
 *
 * Immutable and self-describing: the `label` is not decoration, it is
 * the sentence that appears in the lead's score breakdown, and a rule
 * whose label does not explain it produces a breakdown line nobody can
 * act on. The editor requires one for that reason.
 */
final readonly class ScoringRule {

	/**
	 * Most points one rule may award, in either direction.
	 *
	 * Bounded because the total is a SMALLINT and, more importantly,
	 * because a single rule worth 5,000 points makes every other rule
	 * decorative — and the customer who typed it will report the scoring
	 * as broken rather than as configured.
	 */
	public const MAX_POINTS = 100;

	/**
	 * Free mailbox providers.
	 *
	 * Used only by the `is_business` operator, and deliberately short. A
	 * long list is a maintenance burden that gets stale, and the failure
	 * mode of a missing entry is one lead scoring 15 points higher than
	 * it should — not a broken product.
	 */
	private const FREE_DOMAINS = array(
		'gmail.com',
		'googlemail.com',
		'yahoo.com',
		'yahoo.co.uk',
		'hotmail.com',
		'hotmail.co.uk',
		'outlook.com',
		'live.com',
		'msn.com',
		'aol.com',
		'icloud.com',
		'me.com',
		'mac.com',
		'gmx.com',
		'gmx.de',
		'web.de',
		'proton.me',
		'protonmail.com',
		'mail.com',
		'yandex.ru',
		'qq.com',
		'163.com',
	);

	/**
	 * Construct.
	 *
	 * @param string       $id       Stable key, written onto every event this rule awards.
	 * @param string       $label    The sentence shown in the breakdown.
	 * @param RuleKind     $kind     What the rule looks at.
	 * @param RuleOperator $operator How it compares.
	 * @param int          $points   Signed award.
	 * @param string       $target   Field path, page pattern or metric name.
	 * @param string       $value    What it compares against.
	 * @param bool         $enabled  Whether it runs.
	 * @param bool         $once     Whether it may award more than once per lead.
	 */
	public function __construct(
		public string $id,
		public string $label,
		public RuleKind $kind,
		public RuleOperator $operator,
		public int $points,
		public string $target = '',
		public string $value = '',
		public bool $enabled = true,
		public bool $once = true,
	) {
	}

	/**
	 * Build from stored configuration, or null when it is not a rule.
	 *
	 * Unreadable rules are dropped rather than defaulted. A rule whose
	 * kind failed to parse would otherwise become a working rule with
	 * behaviour the operator never chose, and it would award points they
	 * cannot trace back to anything on the screen.
	 *
	 * @param array<string, mixed> $stored Stored rule.
	 * @return self|null
	 */
	public static function fromArray( array $stored ): ?self {
		$id    = self::text( $stored['id'] ?? null, 64 );
		$kind  = RuleKind::fromStorage( self::text( $stored['kind'] ?? null, 20 ) );
		$label = self::text( $stored['label'] ?? null, 191 );

		if ( '' === $id || null === $kind || '' === $label ) {
			return null;
		}

		$operator = RuleOperator::fromStorage( self::text( $stored['operator'] ?? null, 20 ) )
			?? self::defaultOperator( $kind );

		$points = isset( $stored['points'] ) && is_numeric( $stored['points'] ) ? (int) $stored['points'] : 0;

		if ( 0 === $points ) {
			// A rule worth nothing is not a disabled rule, it is a line in
			// the breakdown that says a thing happened and awarded zero.
			return null;
		}

		return new self(
			id: $id,
			label: $label,
			kind: $kind,
			operator: $operator,
			points: max( -self::MAX_POINTS, min( self::MAX_POINTS, $points ) ),
			target: self::text( $stored['target'] ?? null, 500 ),
			value: self::text( $stored['value'] ?? null, 500 ),
			enabled: (bool) ( $stored['enabled'] ?? true ),
			once: (bool) ( $stored['once'] ?? true ),
		);
	}

	/**
	 * The rule as it is stored and as the editor reads it back.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'       => $this->id,
			'label'    => $this->label,
			'kind'     => $this->kind->value,
			'operator' => $this->operator->value,
			'points'   => $this->points,
			'target'   => $this->target,
			'value'    => $this->value,
			'enabled'  => $this->enabled,
			'once'     => $this->once,
		);
	}

	/**
	 * Whether this rule fires against the facts given.
	 *
	 * @param ScoreSignals $signals What is known.
	 * @return bool
	 */
	public function matches( ScoreSignals $signals ): bool {
		if ( ! $this->enabled ) {
			return false;
		}

		return match ( $this->kind ) {
			RuleKind::Field      => $this->matchesField( $signals ),
			RuleKind::Keyword    => $signals->mentions( self::terms( $this->value ) ),
			RuleKind::Page       => $this->compareNumbers( (float) $signals->pageViews( $this->target ), 1.0 ),
			RuleKind::Engagement => $this->compareNumbers( $signals->metric( $this->target ), 1.0 ),
		};
	}

	/**
	 * Field comparison.
	 *
	 * @param ScoreSignals $signals What is known.
	 * @return bool
	 */
	private function matchesField( ScoreSignals $signals ): bool {
		$actual = $signals->field( $this->target );

		return match ( $this->operator ) {
			RuleOperator::NotEmpty   => null !== $actual,
			RuleOperator::IsEmpty    => null === $actual,
			RuleOperator::IsBusiness => self::isBusinessAddress( $actual ),
			default                  => null !== $actual && $this->compare( $actual ),
		};
	}

	/**
	 * Comparison of a present field value.
	 *
	 * @param string $actual What was found.
	 * @return bool
	 */
	private function compare( string $actual ): bool {
		if ( $this->operator->isNumeric() ) {
			// A currency answer arrives as "£5,000 – £15,000" and the
			// operator's rule says "at least 5000". Reading the first
			// number out of it is the only way that rule can ever fire,
			// and refusing to is how scoring quietly does nothing.
			return $this->compareNumbers( self::firstNumber( $actual ), 0.0 );
		}

		$left  = strtolower( $actual );
		$right = strtolower( trim( $this->value ) );

		return match ( $this->operator ) {
			RuleOperator::Equals    => $left === $right,
			RuleOperator::NotEquals => $left !== $right,
			RuleOperator::Contains  => '' !== $right && str_contains( $left, $right ),
			RuleOperator::Matches   => PathPattern::matches( $this->value, $actual ),
			default                 => false,
		};
	}

	/**
	 * Numeric comparison against the rule's value.
	 *
	 * @param float $actual   What was measured.
	 * @param float $fallback Threshold used when the rule names none.
	 * @return bool
	 */
	private function compareNumbers( float $actual, float $fallback ): bool {
		$threshold = is_numeric( trim( $this->value ) ) ? (float) trim( $this->value ) : $fallback;

		return RuleOperator::LessOrEqual === $this->operator
			? $actual <= $threshold
			: $actual >= $threshold;
	}

	/**
	 * Whether an address belongs to an organisation rather than a person.
	 *
	 * @param string|null $email Address.
	 * @return bool
	 */
	private static function isBusinessAddress( ?string $email ): bool {
		if ( null === $email ) {
			return false;
		}

		$at = strrpos( $email, '@' );

		if ( false === $at ) {
			return false;
		}

		return ! in_array( strtolower( substr( $email, $at + 1 ) ), self::FREE_DOMAINS, true );
	}

	/**
	 * The first number in a string, or zero.
	 *
	 * @param string $value Text.
	 * @return float
	 */
	private static function firstNumber( string $value ): float {
		// Thousands separators go first: "5,000" is one number, and a
		// pattern that reads left to right would otherwise call it 5.
		$stripped = str_replace( array( ',', ' ', "\u{00a0}" ), '', $value );

		if ( 1 === preg_match( '/-?\d+(?:\.\d+)?/', $stripped, $match ) ) {
			return (float) $match[0];
		}

		return 0.0;
	}

	/**
	 * Split a keyword rule's value into terms.
	 *
	 * @param string $value Comma-separated terms.
	 * @return array<int, string>
	 */
	private static function terms( string $value ): array {
		$terms = array();

		foreach ( explode( ',', $value ) as $term ) {
			$trimmed = trim( $term );

			if ( '' !== $trimmed ) {
				$terms[] = $trimmed;
			}
		}

		return $terms;
	}

	/**
	 * The operator a kind assumes when none was stored.
	 *
	 * @param RuleKind $kind Rule kind.
	 * @return RuleOperator
	 */
	private static function defaultOperator( RuleKind $kind ): RuleOperator {
		return match ( $kind ) {
			RuleKind::Field   => RuleOperator::NotEmpty,
			RuleKind::Keyword => RuleOperator::Contains,
			default           => RuleOperator::GreaterOrEqual,
		};
	}

	/**
	 * Read a bounded string from stored configuration.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Maximum length.
	 * @return string
	 */
	private static function text( mixed $value, int $limit ): string {
		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}

		return is_string( $value ) ? substr( trim( $value ), 0, $limit ) : '';
	}
}
