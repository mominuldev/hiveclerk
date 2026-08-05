<?php
/**
 * Facts a scoring rule is evaluated against.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead\Scoring;

/**
 * Everything known about a lead at the moment it is scored.
 *
 * Assembled once and handed to every rule, rather than each rule
 * fetching what it needs. A rule set of forty rules that each queried
 * would be forty round trips on a path that runs after every visitor
 * message, and rules would silently disagree with each other about a
 * transcript that grew between two of them reading it.
 */
final readonly class ScoreSignals {

	/**
	 * Construct.
	 *
	 * @param array<string, string|null> $fields     Lead fields, keyed by column name.
	 * @param array<string, mixed>       $answers    Qualification answers.
	 * @param string                     $transcript What the visitor said, lower-cased.
	 * @param array<string, int>         $pages      Path visit counts.
	 * @param array<string, float>       $metrics    Engagement metrics.
	 */
	public function __construct(
		public array $fields = array(),
		public array $answers = array(),
		public string $transcript = '',
		public array $pages = array(),
		public array $metrics = array(),
	) {
	}

	/**
	 * A field or qualification answer, addressed the way a rule names it.
	 *
	 * `custom.budget` reads an answer; anything else reads a lead column.
	 * One namespace with a prefix rather than two targets, because the
	 * rule editor offers both in one dropdown and the operator does not
	 * think of them as different kinds of thing.
	 *
	 * @param string $path Field path.
	 * @return string|null
	 */
	public function field( string $path ): ?string {
		if ( str_starts_with( $path, 'custom.' ) ) {
			return self::stringify( $this->answers[ substr( $path, 7 ) ] ?? null );
		}

		return self::stringify( $this->fields[ $path ] ?? null );
	}

	/**
	 * How many times a path matching a pattern was seen.
	 *
	 * Summed across matches, so `/products/*` counts a visitor who saw
	 * three different product pages as three views. That is what an
	 * operator means by "looked at products a lot".
	 *
	 * @param string $pattern Path glob.
	 * @return int
	 */
	public function pageViews( string $pattern ): int {
		$total = 0;

		foreach ( $this->pages as $path => $count ) {
			if ( PathPattern::matches( $pattern, $path ) ) {
				$total += $count;
			}
		}

		return $total;
	}

	/**
	 * An engagement metric, zero when it was never measured.
	 *
	 * @param string $name Metric name.
	 * @return float
	 */
	public function metric( string $name ): float {
		return $this->metrics[ $name ] ?? 0.0;
	}

	/**
	 * Whether the visitor used any of these terms.
	 *
	 * Matched on word boundaries so "pro" does not fire on "problem".
	 * A keyword rule that matches inside words scores every lead the
	 * same, which is the same as having no rule.
	 *
	 * @param array<int, string> $terms Terms to look for.
	 * @return bool
	 */
	public function mentions( array $terms ): bool {
		if ( '' === $this->transcript ) {
			return false;
		}

		foreach ( $terms as $term ) {
			$needle = strtolower( trim( $term ) );

			if ( '' === $needle ) {
				continue;
			}

			$expression = '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/iu';

			if ( 1 === preg_match( $expression, $this->transcript ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reduce a stored value to a comparable string.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function stringify( mixed $value ): ?string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed = trim( $value );

		return '' === $trimmed ? null : $trimmed;
	}
}
