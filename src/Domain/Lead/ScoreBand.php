<?php
/**
 * Score band.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

/**
 * A score turned into a word.
 *
 * The band is materialised on the row rather than derived on read, and
 * that is deliberate: the pipeline filters and sorts on it, and a
 * computed band cannot use an index. It also means changing the
 * thresholds does not silently rewrite history — a lead that was hot
 * last month stays hot in the record until something rescores it.
 */
enum ScoreBand: string {

	case Cold      = 'cold';
	case Warm      = 'warm';
	case Hot       = 'hot';
	case Qualified = 'qualified';

	/**
	 * Default lower bound for each band.
	 *
	 * Ordered high to low so the first match wins.
	 */
	public const DEFAULTS = array(
		'qualified' => 75,
		'hot'       => 50,
		'warm'      => 25,
	);

	/**
	 * The band a score falls into.
	 *
	 * @param int                $score      Materialised total.
	 * @param array<string, int> $thresholds Lower bounds, keyed by band.
	 * @return self
	 */
	public static function forScore( int $score, array $thresholds = array() ): self {
		$bounds = array_merge( self::DEFAULTS, $thresholds );

		if ( $score >= (int) $bounds['qualified'] ) {
			return self::Qualified;
		}

		if ( $score >= (int) $bounds['hot'] ) {
			return self::Hot;
		}

		if ( $score >= (int) $bounds['warm'] ) {
			return self::Warm;
		}

		return self::Cold;
	}

	/**
	 * Whether crossing into this band means the lead is worth a person's time.
	 *
	 * @return bool
	 */
	public function isQualified(): bool {
		return self::Qualified === $this;
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Cold      => 'Cold',
			self::Warm      => 'Warm',
			self::Hot       => 'Hot',
			self::Qualified => 'Qualified',
		};
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self
	 */
	public static function fromStorage( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Cold;
	}
}
