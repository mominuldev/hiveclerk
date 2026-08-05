<?php
/**
 * Model provider contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

/**
 * One model provider, normalised.
 *
 * Credentials are passed in rather than injected because a provider
 * instance is shared and stateless while a key belongs to a site — and
 * under multisite, to a specific site in the network. Holding the key on
 * the adapter would make it possible to answer one site's request with
 * another site's account.
 */
interface LlmProviderInterface {

	/**
	 * Stable identifier used in routes, options and audit records.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * What this provider can do.
	 *
	 * @return ProviderCapabilities
	 */
	public function capabilities(): ProviderCapabilities;

	/**
	 * The model to select when the operator has not chosen one.
	 *
	 * @return string
	 */
	public function defaultModel(): string;

	/**
	 * Models available to these credentials.
	 *
	 * Live where the provider exposes a list endpoint, static otherwise.
	 * Either way the operator picks from what their own key can actually
	 * reach, not from a list this plugin was compiled with.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return array<int, Model>
	 *
	 * @throws ProviderException When the provider cannot be reached.
	 */
	public function models( Credentials $credentials ): array;

	/**
	 * Check that these credentials work.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return Verification
	 */
	public function verify( Credentials $credentials ): Verification;

	/**
	 * Produce a complete reply.
	 *
	 * @param Credentials       $credentials Credentials.
	 * @param CompletionRequest $request     Request.
	 * @return Completion
	 *
	 * @throws ProviderException When the call fails.
	 */
	public function complete( Credentials $credentials, CompletionRequest $request ): Completion;

	/**
	 * Produce a reply, emitting events as tokens arrive.
	 *
	 * Never throws once the stream has opened: a mid-stream failure is
	 * delivered as an error event so the caller can close the partial
	 * reply it has already shown the visitor.
	 *
	 * @param Credentials             $credentials Credentials.
	 * @param CompletionRequest       $request     Request.
	 * @param callable(StreamEvent): bool $onEvent Receives each event;
	 *                                             returning false stops it.
	 * @return void
	 */
	public function stream(
		Credentials $credentials,
		CompletionRequest $request,
		callable $onEvent
	): void;

	/**
	 * Published price for a model, when known.
	 *
	 * @param string $model Model identifier.
	 * @return Pricing|null
	 */
	public function pricing( string $model ): ?Pricing;

	/**
	 * Approximate token count for a string.
	 *
	 * Approximate by design. Exact counting needs the provider's own
	 * tokeniser, which is a multi-megabyte dependency per provider for a
	 * number that only ever gates a pre-flight size check — the figures
	 * that reach the customer's bill come from the provider's usage
	 * block, never from here.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public function countTokens( string $text ): int;
}
