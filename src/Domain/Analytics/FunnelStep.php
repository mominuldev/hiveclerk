<?php
/**
 * One rung of the lead funnel.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Analytics;

/**
 * A funnel stage, its count, and its conversion from the rung above.
 *
 * The rate is measured against the previous step rather than against the
 * top of the funnel, because that is the number an operator can act on:
 * "31% of engaged visitors left contact details" names a specific prompt
 * to fix, while "18% of all conversations became leads" names nothing.
 * D11 §10 renders both, and the absolute share is arithmetic the screen
 * can do from the counts.
 */
final class FunnelStep implements \JsonSerializable {

	/**
	 * Construct.
	 *
	 * @param string $key      Machine name.
	 * @param string $label    Row heading.
	 * @param int    $count    How many reached this step.
	 * @param int    $previous How many reached the step above.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly int $count,
		public readonly int $previous = 0
	) {
	}

	/**
	 * Conversion from the step above, or null for the first step.
	 *
	 * @return float|null Fraction between 0 and 1.
	 */
	public function rate(): ?float {
		if ( 0 === $this->previous ) {
			return null;
		}

		return $this->count / $this->previous;
	}

	/**
	 * How many were lost between the step above and this one.
	 *
	 * @return int
	 */
	public function dropOff(): int {
		return max( 0, $this->previous - $this->count );
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$rate = $this->rate();

		return array(
			'key'      => $this->key,
			'label'    => $this->label,
			'count'    => $this->count,
			'rate'     => null === $rate ? null : round( $rate, 4 ),
			'drop_off' => $this->dropOff(),
		);
	}
}
