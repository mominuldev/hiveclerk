<?php
/**
 * One headline number and where it came from.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Analytics;

/**
 * A KPI card: the figure, the comparison, and the shape behind it.
 *
 * The change is `null` rather than `0` when the previous period had
 * nothing to compare against. A card that shows "▲ 0%" against a period
 * in which the site was not yet live is stating a fact that was never
 * measured, and D11 §14.4 is explicit that an invented metric is worse
 * than an absent one.
 *
 * `higherIsBetter` is carried on the KPI rather than decided by the
 * screen because spend is the one card where a rise is not good news, and
 * a component that colours every increase green would say so.
 */
final class Kpi implements \JsonSerializable {

	/**
	 * Construct.
	 *
	 * @param string             $key            Machine name.
	 * @param string             $label          Card heading.
	 * @param float              $value          Current-period figure.
	 * @param float|null         $previous       Same figure for the previous period.
	 * @param array<int, float>  $series         Per-day values, oldest first.
	 * @param string             $format         'number' | 'currency' | 'percent'.
	 * @param bool               $higherIsBetter Whether a rise is good news.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly float $value,
		public readonly ?float $previous = null,
		public readonly array $series = array(),
		public readonly string $format = 'number',
		public readonly bool $higherIsBetter = true
	) {
	}

	/**
	 * Proportional change against the previous period.
	 *
	 * Null when there is no honest comparison to make: no previous period
	 * was requested, or it was empty. Growth from zero is not a percentage
	 * — every implementation that tries reports either infinity or a
	 * plausible lie — so it is reported as "new" by the absence of a
	 * number rather than by a made-up one.
	 *
	 * @return float|null Fraction, where 0.081 is +8.1%.
	 */
	public function change(): ?float {
		if ( null === $this->previous || 0.0 === $this->previous ) {
			return null;
		}

		return ( $this->value - $this->previous ) / abs( $this->previous );
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$change = $this->change();

		return array(
			'key'              => $this->key,
			'label'            => $this->label,
			'value'            => round( $this->value, 4 ),
			'previous'         => null === $this->previous ? null : round( $this->previous, 4 ),
			'change'           => null === $change ? null : round( $change, 4 ),
			'series'           => array_map( static fn ( float $v ): float => round( $v, 4 ), $this->series ),
			'format'           => $this->format,
			'higher_is_better' => $this->higherIsBetter,
		);
	}
}
