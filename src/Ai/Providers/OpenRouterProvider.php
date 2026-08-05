<?php
/**
 * OpenRouter adapter.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai\Providers;

use Hiveclerk\Ai\CompletionRequest;
use Hiveclerk\Ai\Credentials;
use Hiveclerk\Ai\Model;
use Hiveclerk\Ai\Pricing;
use Hiveclerk\Ai\ProviderCapabilities;
use Hiveclerk\Ai\ProviderId;

/**
 * Talks to OpenRouter, which proxies many providers behind one key.
 *
 * OpenRouter is the reason PricingTable has no entries for it. It brokers
 * hundreds of models whose prices change without our release cycle, and it
 * publishes the exact price of each on its own model list — so guessing
 * here would be strictly worse than asking. It also returns the actual
 * cost of each call, which is better still: that figure is what the
 * customer is charged, not an estimate derived from token counts.
 */
final class OpenRouterProvider extends OpenAiCompatibleProvider {

	private const BASE = 'https://openrouter.ai/api/v1';

	/**
	 * Identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return ProviderId::OpenRouter->value;
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function label(): string {
		return ProviderId::OpenRouter->label();
	}

	/**
	 * Capabilities.
	 *
	 * @return ProviderCapabilities
	 */
	public function capabilities(): ProviderCapabilities {
		return new ProviderCapabilities();
	}

	/**
	 * Default model.
	 *
	 * @return string
	 */
	public function defaultModel(): string {
		return 'anthropic/claude-sonnet-4.5';
	}

	/**
	 * Models available through the broker.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return array<int, Model>
	 */
	public function models( Credentials $credentials ): array {
		$json = $this->send( $credentials, 'GET', self::BASE . '/models' );
		$data = $json['data'] ?? array();

		if ( ! is_array( $data ) ) {
			return array();
		}

		$models = array();

		foreach ( $data as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$id = self::stringAt( $entry, 'id' );

			if ( '' === $id ) {
				continue;
			}

			$context = $entry['context_length'] ?? 0;

			$models[] = new Model(
				id: $id,
				label: self::stringAt( $entry, 'name', $id ),
				contextWindow: is_numeric( $context ) ? (int) $context : 0,
				pricing: self::parsePricing( $entry )
			);
		}

		usort( $models, static fn ( Model $a, Model $b ): int => strcmp( $a->label, $b->label ) );

		return $models;
	}

	/**
	 * Completion endpoint.
	 *
	 * @param Credentials       $credentials Credentials.
	 * @param CompletionRequest $request     Request.
	 * @param bool              $stream      Whether the reply will stream.
	 * @return string
	 */
	protected function completionUrl(
		Credentials $credentials,
		CompletionRequest $request,
		bool $stream
	): string {
		unset( $credentials, $request, $stream );

		return self::BASE . '/chat/completions';
	}

	/**
	 * Headers.
	 *
	 * OpenRouter uses the referer and title headers for its public
	 * leaderboard. Sending them identifies the traffic as ours, which is
	 * also what lets a customer recognise our calls in their dashboard.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return array<string, string>
	 */
	protected function headers( Credentials $credentials ): array {
		return array(
			'Authorization' => 'Bearer ' . $credentials->apiKey,
			'HTTP-Referer'  => 'https://hiveclerk.com',
			'X-Title'       => 'Hiveclerk',
			'accept'        => 'application/json',
		);
	}

	/**
	 * OpenRouter still expects the original field name.
	 *
	 * @return string
	 */
	protected function maxTokensField(): string {
		return 'max_tokens';
	}

	/**
	 * Ask for the real cost alongside the token counts.
	 *
	 * @param CompletionRequest $request Request.
	 * @param bool              $stream  Whether to stream.
	 * @return array<string, mixed>
	 */
	protected function payload( CompletionRequest $request, bool $stream ): array {
		$payload          = parent::payload( $request, $stream );
		$payload['usage'] = array( 'include' => true );

		return $payload;
	}

	/**
	 * Cost as OpenRouter itself charged it.
	 *
	 * @param array<string, mixed> $json Decoded body.
	 * @return float|null
	 */
	protected function reportedCost( array $json ): ?float {
		$usage = $json['usage'] ?? null;

		if ( ! is_array( $usage ) || ! isset( $usage['cost'] ) || ! is_numeric( $usage['cost'] ) ) {
			return null;
		}

		return (float) $usage['cost'];
	}

	/**
	 * Convert OpenRouter's per-token strings into our per-million form.
	 *
	 * Prices arrive as decimal strings such as "0.000003" to avoid float
	 * precision loss in JSON, so they are parsed rather than cast blindly.
	 *
	 * @param array<string, mixed> $entry Model entry.
	 * @return Pricing|null
	 */
	private static function parsePricing( array $entry ): ?Pricing {
		$pricing = $entry['pricing'] ?? null;

		if ( ! is_array( $pricing ) ) {
			return null;
		}

		$prompt     = $pricing['prompt'] ?? null;
		$completion = $pricing['completion'] ?? null;

		if ( ! is_numeric( $prompt ) ) {
			return null;
		}

		return new Pricing(
			(float) $prompt * 1_000_000,
			is_numeric( $completion ) ? (float) $completion * 1_000_000 : 0.0
		);
	}
}
