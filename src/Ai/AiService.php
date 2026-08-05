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
final class AiService {

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
