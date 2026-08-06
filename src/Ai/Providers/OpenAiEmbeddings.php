<?php
/**
 * Shared embedding behaviour for OpenAI-shaped endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai\Providers;

use Hiveclerk\Ai\Credentials;
use Hiveclerk\Ai\EmbeddingBatch;
use Hiveclerk\Ai\EmbeddingTask;
use Hiveclerk\Ai\ProviderException;

/**
 * The /embeddings request and response shape, once.
 *
 * OpenAI and Azure OpenAI serve the same body from different URLs behind
 * different auth headers. Duplicating the response handling would mean
 * two places to get the index-ordering guarantee wrong in, and that
 * particular bug does not announce itself — it silently attaches vectors
 * to the wrong chunks.
 */
trait OpenAiEmbeddings {

	/**
	 * Largest batch this provider accepts.
	 *
	 * OpenAI's documented ceiling is 2048 inputs, but the real limit is
	 * the 300k-token request cap, which 96 chunks of ~800 tokens sits well
	 * inside. Sending larger batches trades a rejected request for a
	 * marginal round-trip saving.
	 *
	 * @return int
	 */
	public function maxBatchSize(): int {
		return 96;
	}

	/**
	 * Embed a batch.
	 *
	 * The task is accepted and unused: OpenAI's embedding models are
	 * symmetric, with no distinction to express in the request.
	 *
	 * @param Credentials        $credentials Credentials.
	 * @param array<int, string> $texts       Inputs, in order.
	 * @param string             $model       Model identifier.
	 * @param int                $timeout     Seconds.
	 * @param EmbeddingTask      $task        What the vectors will be used for.
	 * @return EmbeddingBatch
	 *
	 * @throws ProviderException When the call fails or the batch is short.
	 */
	public function embed(
		Credentials $credentials,
		array $texts,
		string $model,
		int $timeout = 60,
		EmbeddingTask $task = EmbeddingTask::Document
	): EmbeddingBatch {
		$this->assertConfigured( $credentials );

		$texts = array_values( $texts );

		if ( array() === $texts ) {
			return new EmbeddingBatch( array(), $this->id(), $model );
		}

		$started = microtime( true );

		$payload = array(
			'model'           => $model,
			'input'           => $texts,
			// Explicit rather than defaulted. The alternative encoding
			// is base64, and a provider changing its default would
			// otherwise arrive as vectors full of zeroes.
			'encoding_format' => 'float',
		);

		$width = $this->embeddingDimensions( $model );

		if ( null !== $width ) {
			$payload['dimensions'] = $width;
		}

		$json = $this->send(
			$credentials,
			'POST',
			$this->embeddingUrl( $credentials, $model ),
			$payload,
			$timeout
		);

		$data = $json['data'] ?? array();

		if ( ! is_array( $data ) ) {
			throw new ProviderException(
				sprintf( '%s returned no embedding data.', $this->label() ),
				$this->id(),
				502
			);
		}

		$vectors = array();

		foreach ( $data as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$embedding = $entry['embedding'] ?? null;

			if ( ! is_array( $embedding ) ) {
				continue;
			}

			// Keyed by the provider's own index rather than appended.
			// The API documents that entries may arrive out of order, and
			// a mis-ordered batch is invisible downstream: retrieval keeps
			// working, it just answers from the wrong chunk.
			$index = isset( $entry['index'] ) && is_numeric( $entry['index'] )
				? (int) $entry['index']
				: count( $vectors );

			$vectors[ $index ] = array_map( 'floatval', array_values( $embedding ) );
		}

		ksort( $vectors );

		if ( count( $vectors ) !== count( $texts ) ) {
			throw new ProviderException(
				sprintf(
					'%s returned %d vectors for %d inputs.',
					$this->label(),
					count( $vectors ),
					count( $texts )
				),
				$this->id(),
				502
			);
		}

		return new EmbeddingBatch(
			vectors: array_values( $vectors ),
			provider: $this->id(),
			model: $model,
			tokensIn: self::intAt( $json, 'usage', 'prompt_tokens' ),
			latencyMs: self::elapsedMs( $started )
		);
	}

	/**
	 * The width to ask this model for, or null to accept its native one.
	 *
	 * The `text-embedding-3-*` family is Matryoshka-trained, so a
	 * truncated vector is still a usable vector rather than a mutilated
	 * one. That matters here because `text-embedding-3-large` is 3,072
	 * dimensions natively and the quantised column holds 2,048 bits — the
	 * choice is between asking for a shorter vector and refusing the model
	 * outright.
	 *
	 * @param string $model Model identifier.
	 * @return int|null
	 */
	protected function embeddingDimensions( string $model ): ?int {
		unset( $model );

		return null;
	}

	/**
	 * Where the embeddings endpoint lives for this provider.
	 *
	 * @param Credentials $credentials Credentials.
	 * @param string      $model       Model identifier.
	 * @return string
	 */
	abstract protected function embeddingUrl( Credentials $credentials, string $model ): string;
}
