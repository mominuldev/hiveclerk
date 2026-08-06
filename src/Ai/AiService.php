<?php
/**
 * Model access facade.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

use Hiveclerk\Domain\Usage\UsageEvent;
use Hiveclerk\Domain\Usage\UsageKind;
use Hiveclerk\Domain\Usage\UsageRepositoryInterface;

/**
 * The one way the rest of the plugin talks to a model.
 *
 * Nothing else resolves a key, picks an adapter or records usage. That
 * concentration is deliberate: metering has to be impossible to forget.
 * If ChatService could call an adapter directly then one code path added
 * later — a retry, a summarisation, a title generator — would silently
 * spend the customer's money without appearing in their cost report, and
 * the report would be wrong in the direction nobody notices.
 */
final class AiService implements AiServiceInterface {

	/**
	 * How long a model list stays cached.
	 *
	 * Long enough that opening the settings screen repeatedly does not
	 * hammer the provider, short enough that a newly granted model shows
	 * up the same working day. The screen also offers an explicit
	 * refresh, which is the real answer for anyone in a hurry.
	 */
	private const MODEL_CACHE_TTL = 6 * HOUR_IN_SECONDS;

	private const MODEL_CACHE_PREFIX = 'hiveclerk_models_';

	private const EMBED_MODEL_CACHE_PREFIX = 'hiveclerk_embed_models_';

	/**
	 * Construct.
	 *
	 * @param ProviderRegistry         $registry Available providers.
	 * @param KeyResolver              $keys     Credential storage.
	 * @param UsageRepositoryInterface $usage    Usage recording.
	 * @param PricingTable             $pricing  Price lookup.
	 */
	public function __construct(
		private readonly ProviderRegistry $registry,
		private readonly KeyResolver $keys,
		private readonly UsageRepositoryInterface $usage,
		private readonly PricingTable $pricing
	) {
	}

	/**
	 * Produce a complete reply and record what it cost.
	 *
	 * @param string            $providerId     Provider identifier.
	 * @param CompletionRequest $request        Request.
	 * @param UsageKind         $kind           What the call is for.
	 * @param int|null          $agentId        Clerk to charge.
	 * @param int|null          $conversationId Conversation to charge.
	 * @return Completion
	 *
	 * @throws ProviderException When the call fails.
	 */
	public function complete(
		string $providerId,
		CompletionRequest $request,
		UsageKind $kind = UsageKind::Chat,
		?int $agentId = null,
		?int $conversationId = null
	): Completion {
		$provider   = $this->registry->get( $providerId );
		$completion = $provider->complete( $this->keys->credentials( $providerId ), $request );

		$this->meter( $completion, $kind, $agentId, $conversationId );

		return $completion;
	}

	/**
	 * Stream a reply and record what it cost when it finishes.
	 *
	 * Metering happens on the done event rather than after the call
	 * returns, because a stream that the visitor abandons still ends —
	 * and the tokens generated before they closed the tab were still
	 * billed by the provider.
	 *
	 * @param string                      $providerId     Provider identifier.
	 * @param CompletionRequest           $request        Request.
	 * @param callable(StreamEvent): bool $onEvent        Event sink.
	 * @param UsageKind                   $kind           What the call is for.
	 * @param int|null                    $agentId        Clerk to charge.
	 * @param int|null                    $conversationId Conversation to charge.
	 * @return void
	 */
	public function stream(
		string $providerId,
		CompletionRequest $request,
		callable $onEvent,
		UsageKind $kind = UsageKind::Chat,
		?int $agentId = null,
		?int $conversationId = null
	): void {
		$provider = $this->registry->get( $providerId );

		$provider->stream(
			$this->keys->credentials( $providerId ),
			$request,
			function ( StreamEvent $event ) use ( $onEvent, $kind, $agentId, $conversationId ): bool {
				if ( StreamEvent::DONE === $event->type && null !== $event->completion ) {
					$this->meter( $event->completion, $kind, $agentId, $conversationId );
				}

				return false !== $onEvent( $event );
			}
		);
	}

	/**
	 * Turn a batch of texts into vectors and record what it cost.
	 *
	 * Routed through here for the same reason completions are: this is the
	 * only place a provider call is metered, and indexing is the single
	 * largest one-off charge the product makes on a customer's account. A
	 * re-index that quietly spent forty dollars and appeared nowhere in
	 * their cost report would be the kind of surprise that ends a trial.
	 *
	 * @param EmbeddingModel     $pin     Pinned provider and model.
	 * @param array<int, string> $texts   Inputs, in order.
	 * @param int                $timeout Seconds.
	 * @param EmbeddingTask      $task    What the vectors will be used for.
	 * @return EmbeddingBatch
	 *
	 * @throws ProviderException When the provider cannot embed or the call fails.
	 */
	public function embed(
		EmbeddingModel $pin,
		array $texts,
		int $timeout = 60,
		EmbeddingTask $task = EmbeddingTask::Document
	): EmbeddingBatch {
		$provider = $this->embedder( $pin->provider );

		$batch = $provider->embed(
			$this->keys->credentials( $pin->provider ),
			$texts,
			$pin->model,
			$timeout,
			$task
		);

		$this->meterEmbedding( $batch );

		return $batch;
	}

	/**
	 * The embedding adapter for a provider.
	 *
	 * @param string $providerId Provider identifier.
	 * @return EmbeddingProviderInterface
	 *
	 * @throws ProviderException When the provider is unknown or cannot embed.
	 */
	public function embedder( string $providerId ): EmbeddingProviderInterface {
		$provider = $this->registry->get( $providerId );

		if ( ! $provider instanceof EmbeddingProviderInterface ) {
			throw new ProviderException(
				sprintf( '%s does not offer an embedding model.', $provider->label() ),
				$providerId,
				409
			);
		}

		return $provider;
	}

	/**
	 * Whether a provider can embed and has a usable key.
	 *
	 * @param string $providerId Provider identifier.
	 * @return bool
	 */
	public function canEmbed( string $providerId ): bool {
		return $this->registry->has( $providerId )
			&& $this->registry->get( $providerId ) instanceof EmbeddingProviderInterface
			&& $this->keys->isConfigured( $providerId );
	}

	/**
	 * Every configured provider that can produce vectors.
	 *
	 * @return array<int, string> Provider identifiers.
	 */
	public function embedders(): array {
		return array_values( array_filter( $this->registry->ids(), fn ( string $id ): bool => $this->canEmbed( $id ) ) );
	}

	/**
	 * Embedding models a provider offers.
	 *
	 * @param string $providerId Provider identifier.
	 * @return array<int, Model>
	 *
	 * @throws ProviderException When the provider cannot be reached.
	 */
	public function embeddingModels( string $providerId ): array {
		$provider = $this->embedder( $providerId );
		$key      = self::EMBED_MODEL_CACHE_PREFIX . $providerId;
		$cached   = get_transient( $key );

		if ( is_array( $cached ) ) {
			return array_values( array_filter( $cached, static fn ( $m ): bool => $m instanceof Model ) );
		}

		$models = $provider->embeddingModels( $this->keys->credentials( $providerId ) );

		set_transient( $key, $models, self::MODEL_CACHE_TTL );

		return $models;
	}

	/**
	 * Models available to a provider's stored credentials.
	 *
	 * @param string $providerId Provider identifier.
	 * @param bool   $refresh    Bypass the cache.
	 * @return array<int, Model>
	 *
	 * @throws ProviderException When the provider cannot be reached.
	 */
	public function models( string $providerId, bool $refresh = false ): array {
		$provider = $this->registry->get( $providerId );

		if ( ! $refresh ) {
			$cached = get_transient( self::MODEL_CACHE_PREFIX . $providerId );

			if ( is_array( $cached ) ) {
				return array_values( array_filter( $cached, static fn ( $m ): bool => $m instanceof Model ) );
			}
		}

		$models = $provider->models( $this->keys->credentials( $providerId ) );

		set_transient( self::MODEL_CACHE_PREFIX . $providerId, $models, self::MODEL_CACHE_TTL );

		return $models;
	}

	/**
	 * Drop a provider's cached model list.
	 *
	 * Called whenever credentials change: the previous list described
	 * what a different key could reach, and showing it beside a new key
	 * would offer models that key may not have.
	 *
	 * @param string $providerId Provider identifier.
	 * @return void
	 */
	public function forgetModels( string $providerId ): void {
		delete_transient( self::MODEL_CACHE_PREFIX . $providerId );
		delete_transient( self::EMBED_MODEL_CACHE_PREFIX . $providerId );
	}

	/**
	 * Check a provider's credentials.
	 *
	 * @param string           $providerId Provider identifier.
	 * @param Credentials|null $override   Credentials to test instead of
	 *                                     the stored ones, so a key can be
	 *                                     checked before it is saved.
	 * @return Verification
	 *
	 * @throws ProviderException When no such provider is registered.
	 */
	public function verify( string $providerId, ?Credentials $override = null ): Verification {
		$provider    = $this->registry->get( $providerId );
		$credentials = $override ?? $this->keys->credentials( $providerId );

		$result = $provider->verify( $credentials );

		if ( $result->ok ) {
			$this->keys->markVerified( $providerId, $result->modelCount );
			$this->forgetModels( $providerId );
		}

		return $result;
	}

	/**
	 * Whether a provider is ready to serve.
	 *
	 * @param string $providerId Provider identifier.
	 * @return bool
	 */
	public function isReady( string $providerId ): bool {
		return $this->registry->has( $providerId )
			&& $this->keys->isConfigured( $providerId );
	}

	/**
	 * The registry, for callers that need to describe providers.
	 *
	 * @return ProviderRegistry
	 */
	public function registry(): ProviderRegistry {
		return $this->registry;
	}

	/**
	 * Write a usage record for a completed call.
	 *
	 * @param Completion $completion     Result.
	 * @param UsageKind  $kind           What the call was for.
	 * @param int|null   $agentId        Clerk to charge.
	 * @param int|null   $conversationId Conversation to charge.
	 * @return void
	 */
	private function meter(
		Completion $completion,
		UsageKind $kind,
		?int $agentId,
		?int $conversationId
	): void {
		$event = new UsageEvent(
			kind: $kind,
			provider: $completion->provider,
			model: $completion->model,
			tokensIn: $completion->tokensIn,
			tokensOut: $completion->tokensOut,
			cost: $this->costOf( $completion ),
			agentId: $agentId,
			conversationId: $conversationId,
			latencyMs: $completion->latencyMs > 0 ? $completion->latencyMs : null
		);

		$this->usage->record( $event );

		/**
		 * Fires after a provider call is metered.
		 *
		 * @param UsageEvent $event      Recorded event.
		 * @param Completion $completion The call it came from.
		 */
		do_action( 'hiveclerk/usage/recorded', $event, $completion );
	}

	/**
	 * Write a usage record for a completed embedding call.
	 *
	 * @param EmbeddingBatch $batch Result.
	 * @return void
	 */
	private function meterEmbedding( EmbeddingBatch $batch ): void {
		$event = new UsageEvent(
			kind: UsageKind::Embedding,
			provider: $batch->provider,
			model: $batch->model,
			tokensIn: $batch->tokensIn,
			tokensOut: 0,
			// A provider that reports no token count leaves the cost
			// unknown, not zero. Gemini's batch endpoint is the case that
			// matters: computing a price from a token count of zero would
			// record every indexing run on that provider as free, and the
			// month's spend would be understated by the whole of it.
			cost: $batch->tokensIn > 0
				? $this->pricing->cost( $batch->provider, $batch->model, $batch->tokensIn )
				: null,
			latencyMs: $batch->latencyMs > 0 ? $batch->latencyMs : null
		);

		$this->usage->record( $event );

		/**
		 * Fires after an embedding call is metered.
		 *
		 * @param UsageEvent     $event Recorded event.
		 * @param EmbeddingBatch $batch The call it came from.
		 */
		do_action( 'hiveclerk/usage/embedded', $event, $batch );
	}

	/**
	 * What a completion cost, when that can be known.
	 *
	 * A provider that reports its own cost is believed over the table:
	 * that figure is what the customer is actually charged, including
	 * whatever discount, cache hit or routing decision applied.
	 *
	 * @param Completion $completion Result.
	 * @return float|null
	 */
	private function costOf( Completion $completion ): ?float {
		if ( null !== $completion->reportedCost ) {
			return $completion->reportedCost;
		}

		return $this->pricing->cost(
			$completion->provider,
			$completion->model,
			$completion->tokensIn,
			$completion->tokensOut
		);
	}
}
