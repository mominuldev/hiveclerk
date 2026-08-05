<?php
/**
 * Aggregated usage.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Usage;

/**
 * Totals for one slice of usage.
 *
 * Carries an unpriced call count alongside the cost. A total that omits
 * calls it could not price looks the same as a total that priced
 * everything, and the difference is exactly what an operator needs to
 * know before trusting the figure.
 */
final class UsageSummary implements \JsonSerializable {

	/**
	 * Construct.
	 *
	 * @param string $label     What this slice is: a date, a model, or "all".
	 * @param int    $calls     Number of calls.
	 * @param int    $tokensIn  Input tokens.
	 * @param int    $tokensOut Output tokens.
	 * @param float  $cost      Cost in USD of the calls that were priced.
	 * @param int    $unpriced  Calls with no published price.
	 * @param string $provider  Provider, where the slice is per-model.
	 */
	public function __construct(
		public readonly string $label,
		public readonly int $calls = 0,
		public readonly int $tokensIn = 0,
		public readonly int $tokensOut = 0,
		public readonly float $cost = 0.0,
		public readonly int $unpriced = 0,
		public readonly string $provider = ''
	) {
	}

	/**
	 * Whether every call in this slice had a known price.
	 *
	 * @return bool
	 */
	public function isComplete(): bool {
		return 0 === $this->unpriced;
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'label'      => $this->label,
			'provider'   => $this->provider,
			'calls'      => $this->calls,
			'tokens_in'  => $this->tokensIn,
			'tokens_out' => $this->tokensOut,
			'cost'       => round( $this->cost, 4 ),
			'unpriced'   => $this->unpriced,
			'complete'   => $this->isComplete(),
		);
	}
}
