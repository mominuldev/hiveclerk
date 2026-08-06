<?php
/**
 * Published model prices.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

/**
 * Maps a provider and model to its published price.
 *
 * Three things make this harder than a lookup array, and each is handled
 * explicitly rather than papered over:
 *
 * 1. **Model ids carry dated suffixes.** Providers ship
 *    `claude-sonnet-4-5-20250929` and `gpt-5-2025-08-07` while publishing
 *    prices against the undated family name, so a match falls back to the
 *    longest prefix.
 * 2. **Prices change.** A hard-coded table goes stale between releases, so
 *    every entry is filterable and a provider that reports its own cost
 *    on the response wins over this table.
 * 3. **An unknown model is not free.** A miss returns null, and the caller
 *    records the usage with no cost and shows it as unpriced. Reporting
 *    zero would quietly understate spend, which is worse than admitting
 *    the gap.
 */
final class PricingTable {

	/**
	 * The date these figures were last checked against provider pages.
	 *
	 * Surfaced in the UI so nobody mistakes an estimate for an invoice.
	 *
	 * It is the date the *oldest* row was verified, not the newest. The
	 * Google entries were re-checked on 2026-08-06 and the rest were not,
	 * so moving this forward would claim a check of Anthropic, OpenAI and
	 * Azure prices that nobody performed. A date that understates how
	 * fresh some rows are is harmless; one that overstates it is the same
	 * class of mistake as reporting an unpriced call as free.
	 */
	public const AS_OF = '2026-02-01';

	/**
	 * Resolved table, built once per request.
	 *
	 * @var array<string, Pricing>|null
	 */
	private ?array $table = null;

	/**
	 * Price for a model.
	 *
	 * @param string $provider Provider identifier.
	 * @param string $model    Model identifier, dated suffix allowed.
	 * @return Pricing|null Null when the model is not in the table.
	 */
	public function for( string $provider, string $model ): ?Pricing {
		$table = $this->table();
		$key   = $provider . ':' . $model;

		if ( isset( $table[ $key ] ) ) {
			return $table[ $key ];
		}

		return $this->longestPrefixMatch( $table, $provider, $model );
	}

	/**
	 * Cost of a call, or null when the model is not priced.
	 *
	 * @param string $provider  Provider identifier.
	 * @param string $model     Model identifier.
	 * @param int    $tokensIn  Input tokens.
	 * @param int    $tokensOut Output tokens.
	 * @return float|null
	 */
	public function cost(
		string $provider,
		string $model,
		int $tokensIn,
		int $tokensOut = 0
	): ?float {
		return $this->for( $provider, $model )?->cost( $tokensIn, $tokensOut );
	}

	/**
	 * Find the most specific family price for a dated model id.
	 *
	 * `gpt-5-mini-2026-01-14` must match `gpt-5-mini` and not `gpt-5`,
	 * which is why this takes the longest match rather than the first.
	 *
	 * @param array<string, Pricing> $table    Resolved table.
	 * @param string                 $provider Provider identifier.
	 * @param string                 $model    Model identifier.
	 * @return Pricing|null
	 */
	private function longestPrefixMatch( array $table, string $provider, string $model ): ?Pricing {
		$prefix = $provider . ':';
		$best   = null;
		$length = 0;

		foreach ( $table as $key => $pricing ) {
			if ( ! str_starts_with( $key, $prefix ) ) {
				continue;
			}

			$family = substr( $key, strlen( $prefix ) );

			if ( str_starts_with( $model, $family ) && strlen( $family ) > $length ) {
				$best   = $pricing;
				$length = strlen( $family );
			}
		}

		return $best;
	}

	/**
	 * Build and filter the table.
	 *
	 * @return array<string, Pricing>
	 */
	private function table(): array {
		if ( null !== $this->table ) {
			return $this->table;
		}

		$table = self::published();

		/**
		 * Adjust or extend published model prices.
		 *
		 * A site on negotiated enterprise rates, or running a model this
		 * build has never heard of, corrects its cost reporting here.
		 *
		 * @param array<string, Pricing> $table Keyed "provider:model".
		 */
		$filtered = apply_filters( 'hiveclerk/pricing', $table );

		$this->table = is_array( $filtered ) ? $filtered : $table;

		return $this->table;
	}

	/**
	 * List prices in USD per million tokens.
	 *
	 * OpenRouter is absent deliberately: it proxies hundreds of models at
	 * prices it publishes per request, so its adapter reads cost from the
	 * response rather than guessing here.
	 *
	 * @return array<string, Pricing>
	 */
	private static function published(): array {
		return array(
			// Anthropic.
			'anthropic:claude-opus-4'       => new Pricing( 15.00, 75.00 ),
			'anthropic:claude-sonnet-4'     => new Pricing( 3.00, 15.00 ),
			'anthropic:claude-haiku-4'      => new Pricing( 1.00, 5.00 ),
			'anthropic:claude-3-5-haiku'    => new Pricing( 0.80, 4.00 ),

			// OpenAI.
			'openai:gpt-5'                  => new Pricing( 1.25, 10.00 ),
			'openai:gpt-5-mini'             => new Pricing( 0.25, 2.00 ),
			'openai:gpt-5-nano'             => new Pricing( 0.05, 0.40 ),
			'openai:gpt-4.1'                => new Pricing( 2.00, 8.00 ),
			'openai:gpt-4.1-mini'           => new Pricing( 0.40, 1.60 ),
			'openai:gpt-4o-mini'            => new Pricing( 0.15, 0.60 ),
			'openai:text-embedding-3-small' => new Pricing( 0.02 ),
			'openai:text-embedding-3-large' => new Pricing( 0.13 ),

			// Google.
			//
			// The 3.x families are listed before the 2.5 ones only for
			// readability; `longestPrefixMatch()` decides what a dated id
			// resolves to, and `gemini-3.1-flash-lite` cannot collide with
			// `gemini-3.1-flash` because no such family is published.
			//
			// Audio input on 3.1 Flash-Lite is billed at $0.50 rather than
			// $0.25. Not modelled: `Pricing` carries one input rate, this
			// product sends text, and a second rate that nothing can reach
			// would be a number nobody could check.
			'google:gemini-3.5-flash'       => new Pricing( 1.50, 9.00 ),
			'google:gemini-3.1-flash-lite'  => new Pricing( 0.25, 1.50 ),
			'google:gemini-2.5-pro'         => new Pricing( 1.25, 10.00 ),
			'google:gemini-2.5-flash'       => new Pricing( 0.30, 2.50 ),
			'google:gemini-2.5-flash-lite'  => new Pricing( 0.10, 0.40 ),
			'google:text-embedding-004'     => new Pricing( 0.0 ),

			/*
			 * Priced, and still reported unpriced on every call — which is
			 * correct, and worth explaining here because it looks like a
			 * missing entry and is not.
			 *
			 * Google's `batchEmbedContents` returns no token usage, so
			 * `GoogleProvider` reports `tokensIn: 0` and `AiService` records
			 * the cost as unknown rather than multiplying this rate by zero.
			 * A site indexing through Gemini therefore sees its embedding
			 * calls counted as unpriced no matter what this row says. The
			 * fix is a token count from the provider or an estimate that
			 * declares itself as one, not a price.
			 */
			'google:gemini-embedding-001'   => new Pricing( 0.15 ),

			// Azure bills the same rates under deployment names, so the
			// family prefixes are repeated rather than aliased: a customer
			// may name a deployment anything, and matching on the model
			// field the API returns is the only reliable hook.
			'azure:gpt-5'                   => new Pricing( 1.25, 10.00 ),
			'azure:gpt-5-mini'              => new Pricing( 0.25, 2.00 ),
			'azure:gpt-4.1'                 => new Pricing( 2.00, 8.00 ),
			'azure:gpt-4.1-mini'            => new Pricing( 0.40, 1.60 ),
			'azure:text-embedding-3-small'  => new Pricing( 0.02 ),
			'azure:text-embedding-3-large'  => new Pricing( 0.13 ),
		);
	}
}
