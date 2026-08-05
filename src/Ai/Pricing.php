<?php
/**
 * Token pricing.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

/**
 * What a model charges, in USD per million tokens.
 *
 * Per million rather than per token because that is the unit every
 * provider publishes, and converting once at the boundary avoids a
 * scattering of 1e-6 factors that are easy to get wrong by a factor of
 * a thousand.
 */
final class Pricing implements \JsonSerializable {

	private const PER_MILLION = 1_000_000;

	/**
	 * Construct.
	 *
	 * @param float $inputPerMillion  USD per million input tokens.
	 * @param float $outputPerMillion USD per million output tokens.
	 */
	public function __construct(
		public readonly float $inputPerMillion,
		public readonly float $outputPerMillion = 0.0
	) {
	}

	/**
	 * Cost in USD for a given token count.
	 *
	 * @param int $tokensIn  Input tokens.
	 * @param int $tokensOut Output tokens.
	 * @return float
	 */
	public function cost( int $tokensIn, int $tokensOut = 0 ): float {
		$total = ( $tokensIn * $this->inputPerMillion + $tokensOut * $this->outputPerMillion )
			/ self::PER_MILLION;

		// The usage table stores DECIMAL(12,6); rounding here rather than
		// letting MySQL truncate keeps the reported figure and the stored
		// figure identical.
		return round( $total, 6 );
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, float|string>
	 */
	public function jsonSerialize(): array {
		return array(
			'input_per_million'  => $this->inputPerMillion,
			'output_per_million' => $this->outputPerMillion,
			'currency'           => 'USD',
		);
	}
}
