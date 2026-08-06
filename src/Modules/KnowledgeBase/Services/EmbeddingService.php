<?php
/**
 * Turns chunks into vectors.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Services;

use Hiveclerk\Ai\AiService;
use Hiveclerk\Ai\EmbeddingBatch;
use Hiveclerk\Ai\EmbeddingModel;
use Hiveclerk\Ai\EmbeddingTask;
use Hiveclerk\Ai\ProviderException;
use Hiveclerk\Ai\ProviderId;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Domain\Knowledge\Chunk;
use Hiveclerk\Domain\Knowledge\Embedding;
use Hiveclerk\Modules\KnowledgeBase\Vector\BinaryQuantiser;
use Closure;

/**
 * Embedding with the three properties the job above it depends on
 * (FR-KB-07, TD-6).
 *
 * **Batching.** Ninety-six inputs per call. A chunk at a time is one HTTP
 * round trip per chunk, which on a five-thousand-chunk site is most of an
 * hour spent waiting rather than computing.
 *
 * **Retry that distinguishes causes.** A 429 is the provider asking us to
 * slow down and a retry will work; a 401 will fail identically forever
 * and belongs on the operator's screen, not in a retry loop burning
 * through a queue. A 400 usually means one input in the batch is too
 * large, so the batch is halved and each half retried — losing one bad
 * chunk instead of ninety-six good ones.
 *
 * **Pinning.** The provider, model and width used are recorded against
 * the source. Vectors from two models are not comparable, and searching
 * one with the other returns confident nonsense rather than an error, so
 * a mismatch has to be detectable after the fact.
 */
final class EmbeddingService {

	/**
	 * Inputs per provider call.
	 */
	public const BATCH = 96;

	/**
	 * Attempts before a batch is given up on.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Seconds to wait before each retry.
	 *
	 * @var array<int, int>
	 */
	private const BACKOFF = array( 1, 4 );

	/**
	 * How long a query embedding stays cached.
	 *
	 * Query vectors are cached because a support page linked from a
	 * newsletter produces the same question hundreds of times in an hour,
	 * and each cache hit is one fewer provider round trip on the latency
	 * path the visitor is actually waiting on.
	 */
	private const QUERY_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	private const QUERY_CACHE_PREFIX = 'hvc_qvec_';

	/**
	 * Construct.
	 *
	 * @param AiService          $ai       Metered model access.
	 * @param SettingsRepository $settings Settings.
	 * @param Closure|null       $sleep    Delay function; null uses sleep().
	 */
	public function __construct(
		private readonly AiService $ai,
		private readonly SettingsRepository $settings,
		private readonly ?Closure $sleep = null
	) {
	}

	/**
	 * The embedding model this site is configured to use.
	 *
	 * @return EmbeddingModel|null Null when nothing usable is configured.
	 */
	public function configured(): ?EmbeddingModel {
		$provider = $this->settings->get( 'retrieval.embed_provider' );
		$model    = $this->settings->get( 'retrieval.embed_model' );

		if ( is_string( $provider ) && is_string( $model ) && '' !== $provider && '' !== $model ) {
			if ( $this->ai->canEmbed( $provider ) ) {
				return new EmbeddingModel( $provider, $model );
			}
		}

		return $this->firstAvailable();
	}

	/**
	 * The first configured provider that can embed, with its default model.
	 *
	 * A fallback rather than a default. An operator who has pasted one API
	 * key and indexed a source should not have to find a second settings
	 * screen before retrieval works — but the choice is still recorded
	 * against the source, so the fallback is visible after the fact rather
	 * than being an invisible decision made on their behalf.
	 *
	 * @return EmbeddingModel|null
	 */
	public function firstAvailable(): ?EmbeddingModel {
		foreach ( ProviderId::cases() as $candidate ) {
			if ( ! $candidate->canEmbed() || ! $this->ai->canEmbed( $candidate->value ) ) {
				continue;
			}

			$model = $this->ai->embedder( $candidate->value )->defaultEmbeddingModel();

			if ( '' !== $model ) {
				return new EmbeddingModel( $candidate->value, $model );
			}
		}

		return null;
	}

	/**
	 * Embed a batch of chunks, in order.
	 *
	 * @param array<int, Chunk> $chunks Chunks.
	 * @param EmbeddingModel    $pin    Provider and model to use.
	 * @return array<int, Embedding> Keyed by chunk id; failures are absent.
	 *
	 * @throws ProviderException When the provider fails in a way a retry cannot fix.
	 */
	public function embedChunks( array $chunks, EmbeddingModel $pin ): array {
		$chunks = array_values( $chunks );

		if ( array() === $chunks ) {
			return array();
		}

		$ids   = array();
		$texts = array();

		foreach ( $chunks as $chunk ) {
			if ( null === $chunk->id ) {
				continue;
			}

			$ids[] = $chunk->id;
			// The heading path is prefixed rather than the bare text. "Free
			// returns within 30 days" means one thing under *Shipping* and
			// another under *Wholesale terms*, and the sentence alone does
			// not say which — so neither does its vector.
			$texts[] = $chunk->contextualised();
		}

		if ( array() === $texts ) {
			return array();
		}

		$vectors = $this->send( $texts, $pin );
		$result  = array();

		foreach ( $vectors as $index => $vector ) {
			if ( ! isset( $ids[ $index ] ) ) {
				continue;
			}

			$result[ $ids[ $index ] ] = $vector;
		}

		return $result;
	}

	/**
	 * Embed a single query.
	 *
	 * @param string         $text  Query text.
	 * @param EmbeddingModel $pin   Provider and model to use.
	 * @param bool           $cache Whether an identical query may be reused.
	 * @return Embedding
	 *
	 * @throws ProviderException When the call fails.
	 */
	public function embedQuery( string $text, EmbeddingModel $pin, bool $cache = true ): Embedding {
		$text = trim( $text );

		if ( '' === $text ) {
			return new Embedding( array(), $pin->provider, $pin->model );
		}

		// The task type is part of the key: a query vector and a document
		// vector of the same text are different vectors on an asymmetric
		// model, and serving one as the other is a silent recall loss.
		$key = self::QUERY_CACHE_PREFIX . md5(
			$pin->provider . '|' . $pin->model . '|' . EmbeddingTask::Query->value . '|' . $text
		);

		if ( $cache ) {
			$cached = get_transient( $key );

			if ( is_array( $cached ) && array() !== $cached ) {
				return new Embedding(
					array_map( 'floatval', array_values( $cached ) ),
					$pin->provider,
					$pin->model
				);
			}
		}

		$batch  = $this->ai->embed( $pin, array( $text ), 60, EmbeddingTask::Query );
		$vector = $batch->at( 0 );

		if ( null === $vector ) {
			throw new ProviderException(
				'The embedding provider returned no vector for the query.',
				$pin->provider,
				502
			);
		}

		if ( $cache ) {
			set_transient( $key, $vector->vector, self::QUERY_CACHE_TTL );
		}

		return $vector;
	}

	/**
	 * Estimated cost of embedding a number of tokens.
	 *
	 * Used by the crawl preview so a customer sees the bill before they
	 * agree to it (R-3). Returns null when the model has no published
	 * price rather than guessing — an invented estimate is worse than
	 * saying the price is not known.
	 *
	 * @param EmbeddingModel $pin    Provider and model.
	 * @param int            $tokens Token count.
	 * @return float|null USD.
	 */
	public function estimateCost( EmbeddingModel $pin, int $tokens ): ?float {
		if ( $tokens <= 0 ) {
			return 0.0;
		}

		if ( ! $this->ai->registry()->has( $pin->provider ) ) {
			return null;
		}

		return $this->ai->registry()->get( $pin->provider )->pricing( $pin->model )?->cost( $tokens );
	}

	/**
	 * Send one logical batch, splitting and retrying as needed.
	 *
	 * @param array<int, string> $texts Inputs, in order.
	 * @param EmbeddingModel     $pin   Provider and model.
	 * @return array<int, Embedding> In input order; failures are absent.
	 *
	 * @throws ProviderException When the failure is not one a retry or a split can fix.
	 */
	private function send( array $texts, EmbeddingModel $pin ): array {
		$out = array();

		$size = max( 1, min( self::BATCH, $this->batchLimit( $pin ) ) );

		foreach ( array_chunk( $texts, $size, true ) as $slice ) {
			foreach ( $this->attempt( $slice, $pin ) as $index => $vector ) {
				$out[ $index ] = $vector;
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Call the provider for one batch, with retry and splitting.
	 *
	 * @param array<int, string> $slice   Inputs, keyed by their position.
	 * @param EmbeddingModel     $pin     Provider and model.
	 * @param int                $attempt Attempt number, from 1.
	 * @return array<int, Embedding> Keyed by the same positions.
	 *
	 * @throws ProviderException When the failure is unrecoverable.
	 */
	private function attempt( array $slice, EmbeddingModel $pin, int $attempt = 1 ): array {
		if ( array() === $slice ) {
			return array();
		}

		try {
			$batch = $this->ai->embed( $pin, array_values( $slice ) );

			return $this->align( $slice, $batch, $pin );
		} catch ( ProviderException $e ) {
			if ( $e->retryable && $attempt < self::MAX_ATTEMPTS ) {
				$this->pause( self::BACKOFF[ $attempt - 1 ] ?? self::BACKOFF[ count( self::BACKOFF ) - 1 ] );

				return $this->attempt( $slice, $pin, $attempt + 1 );
			}

			if ( $this->isSizeFailure( $e ) && count( $slice ) > 1 ) {
				// Almost always one oversized input, and there is no way to
				// tell which from the error. Halving isolates it in
				// log2(n) calls and saves the other ninety-five.
				$half   = max( 1, (int) ceil( count( $slice ) / 2 ) );
				$halves = array_chunk( $slice, $half, true );
				$out    = array();

				foreach ( $halves as $half ) {
					foreach ( $this->attempt( $half, $pin, 1 ) as $index => $vector ) {
						$out[ $index ] = $vector;
					}
				}

				return $out;
			}

			if ( $this->isSizeFailure( $e ) ) {
				// A single input the provider will not accept. Dropped with
				// a note rather than failing the source: one unindexable
				// chunk must not cost the customer the other five thousand.
				/**
				 * Fires when one chunk cannot be embedded.
				 *
				 * @param ProviderException $e   The refusal.
				 * @param EmbeddingModel    $pin Provider and model.
				 */
				do_action( 'hiveclerk/knowledge/chunk_unembeddable', $e, $pin );

				return array();
			}

			throw $e;
		}
	}

	/**
	 * Match a provider's vectors back to the positions they came from.
	 *
	 * @param array<int, string> $slice Inputs, keyed by position.
	 * @param EmbeddingBatch     $batch Provider response.
	 * @param EmbeddingModel     $pin   Provider and model.
	 * @return array<int, Embedding>
	 *
	 * @throws ProviderException When the vectors are too wide to store.
	 */
	private function align( array $slice, EmbeddingBatch $batch, EmbeddingModel $pin ): array {
		$width = $batch->dimensions();

		if ( $width > BinaryQuantiser::MAX_DIMENSIONS ) {
			throw new ProviderException(
				sprintf(
					'%s returns %d-dimension vectors and Hiveclerk\'s index holds %d. '
					. 'Choose an embedding model with fewer dimensions.',
					$pin->model,
					$width,
					BinaryQuantiser::MAX_DIMENSIONS
				),
				$pin->provider,
				422
			);
		}

		$positions = array_keys( $slice );
		$aligned   = array();

		foreach ( $batch->embeddings() as $index => $vector ) {
			if ( ! isset( $positions[ $index ] ) ) {
				continue;
			}

			$aligned[ $positions[ $index ] ] = $vector;
		}

		return $aligned;
	}

	/**
	 * Whether a failure suggests the batch was too large.
	 *
	 * @param ProviderException $e Failure.
	 * @return bool
	 */
	private function isSizeFailure( ProviderException $e ): bool {
		if ( 413 === $e->status ) {
			return true;
		}

		if ( 400 !== $e->status && 422 !== $e->status ) {
			return false;
		}

		$message = strtolower( $e->getMessage() );

		foreach ( array( 'maximum context', 'too long', 'too large', 'token', 'length', 'exceed' ) as $hint ) {
			if ( str_contains( $message, $hint ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The provider's own batch ceiling.
	 *
	 * @param EmbeddingModel $pin Provider and model.
	 * @return int
	 */
	private function batchLimit( EmbeddingModel $pin ): int {
		try {
			return max( 1, $this->ai->embedder( $pin->provider )->maxBatchSize() );
		} catch ( ProviderException ) {
			return self::BATCH;
		}
	}

	/**
	 * Wait before a retry.
	 *
	 * @param int $seconds Delay.
	 * @return void
	 */
	private function pause( int $seconds ): void {
		if ( null !== $this->sleep ) {
			( $this->sleep )( $seconds );

			return;
		}

		sleep( $seconds );
	}
}
