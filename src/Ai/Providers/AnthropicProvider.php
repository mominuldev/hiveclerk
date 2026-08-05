<?php
/**
 * Anthropic adapter.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai\Providers;

use Hiveclerk\Ai\Completion;
use Hiveclerk\Ai\CompletionRequest;
use Hiveclerk\Ai\Credentials;
use Hiveclerk\Ai\Model;
use Hiveclerk\Ai\ProviderCapabilities;
use Hiveclerk\Ai\ProviderId;
use Hiveclerk\Ai\StreamEvent;
use Hiveclerk\Ai\Streaming\SseFrame;
use Hiveclerk\Ai\Streaming\StreamState;

/**
 * Talks to the Anthropic Messages API.
 *
 * Two things here are not shared with the OpenAI-shaped providers. The
 * system prompt is a top-level field rather than a leading message, and
 * the stream is a properly named event sequence rather than a run of
 * anonymous chunks — which means usage arrives in two pieces, input
 * tokens at the start and output tokens at the end.
 */
final class AnthropicProvider extends AbstractProvider {

	private const BASE    = 'https://api.anthropic.com/v1';
	private const VERSION = '2023-06-01';

	/**
	 * Identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return ProviderId::Anthropic->value;
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function label(): string {
		return ProviderId::Anthropic->label();
	}

	/**
	 * Capabilities.
	 *
	 * @return ProviderCapabilities
	 */
	public function capabilities(): ProviderCapabilities {
		// No embedding model is offered, which is why a site using
		// Anthropic for chat still needs a second provider for retrieval.
		return new ProviderCapabilities( embeddings: false );
	}

	/**
	 * Default model.
	 *
	 * @return string
	 */
	public function defaultModel(): string {
		return 'claude-sonnet-4-5';
	}

	/**
	 * Models this key can reach.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return array<int, Model>
	 */
	public function models( Credentials $credentials ): array {
		$json = $this->send( $credentials, 'GET', self::BASE . '/models?limit=100' );
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

			$models[] = new Model(
				id: $id,
				label: self::stringAt( $entry, 'display_name', $id ),
				contextWindow: 200_000,
				maxOutput: 8_192,
				pricing: $this->pricing( $id )
			);
		}

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

		return self::BASE . '/messages';
	}

	/**
	 * Headers.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return array<string, string>
	 */
	protected function headers( Credentials $credentials ): array {
		return array(
			'x-api-key'         => $credentials->apiKey,
			'anthropic-version' => self::VERSION,
			'accept'            => 'application/json',
		);
	}

	/**
	 * Request body.
	 *
	 * @param CompletionRequest $request Request.
	 * @param bool              $stream  Whether to stream.
	 * @return array<string, mixed>
	 */
	protected function payload( CompletionRequest $request, bool $stream ): array {
		$messages = array();

		foreach ( $request->turns as $turn ) {
			$messages[] = array(
				'role'    => $turn->isUser() ? 'user' : 'assistant',
				'content' => $turn->content,
			);
		}

		$payload = array(
			'model'       => $request->model,
			'max_tokens'  => $request->maxTokens,
			'temperature' => $request->temperature,
			'messages'    => $messages,
			'stream'      => $stream,
		);

		if ( '' !== $request->system ) {
			$payload['system'] = $request->system;
		}

		if ( array() !== $request->stop ) {
			$payload['stop_sequences'] = array_values( $request->stop );
		}

		return $payload;
	}

	/**
	 * Read a non-streamed response.
	 *
	 * @param array<string, mixed> $json    Decoded body.
	 * @param CompletionRequest    $request Original request.
	 * @return Completion
	 */
	protected function parseCompletion( array $json, CompletionRequest $request ): Completion {
		$blocks = $json['content'] ?? array();
		$text   = '';

		// The response is a list of content blocks. Only text blocks are
		// concatenated: a tool-use block joined into the reply would print
		// raw JSON to the visitor.
		if ( is_array( $blocks ) ) {
			foreach ( $blocks as $block ) {
				if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
					$text .= self::stringAt( $block, 'text' );
				}
			}
		}

		return new Completion(
			text: $text,
			model: self::stringAt( $json, 'model', $request->model ),
			provider: $this->id(),
			tokensIn: self::intAt( $json, 'usage', 'input_tokens' ),
			tokensOut: self::intAt( $json, 'usage', 'output_tokens' ),
			finishReason: self::stringAt( $json, 'stop_reason', 'stop' )
		);
	}

	/**
	 * Translate a stream frame.
	 *
	 * @param SseFrame    $frame Frame.
	 * @param StreamState $state Running state.
	 * @return array<int, StreamEvent>
	 */
	protected function translate( SseFrame $frame, StreamState $state ): array {
		$data = $frame->json();

		switch ( $frame->event ) {
			case 'message_start':
				$message = $data['message'] ?? array();

				if ( is_array( $message ) ) {
					$state->model    = self::stringAt( $message, 'model', $state->model );
					$state->tokensIn = self::intAt( $message, 'usage', 'input_tokens' );
				}

				return array();

			case 'content_block_delta':
				$delta = $data['delta'] ?? array();

				if ( ! is_array( $delta ) || 'text_delta' !== ( $delta['type'] ?? '' ) ) {
					return array();
				}

				$text = self::stringAt( $delta, 'text' );

				if ( '' === $text ) {
					return array();
				}

				$state->append( $text );

				return array( StreamEvent::delta( $text ) );

			case 'message_delta':
				// Output tokens are only final here, and only here — the
				// closing message_stop carries no usage at all.
				$state->tokensOut = self::intAt( $data, 'usage', 'output_tokens' );

				$delta = $data['delta'] ?? array();

				if ( is_array( $delta ) ) {
					$state->finishReason = self::stringAt( $delta, 'stop_reason', $state->finishReason );
				}

				return array();

			case 'message_stop':
				$state->finished = true;

				return array( StreamEvent::done( $state->toCompletion() ) );

			case 'error':
				$error   = $data['error'] ?? array();
				$message = is_array( $error )
					? self::stringAt( $error, 'message', 'The provider reported an error.' )
					: 'The provider reported an error.';

				$state->finished = true;

				return array( StreamEvent::error( $message, true ) );

			default:
				// ping, content_block_start, content_block_stop: structural
				// frames with nothing the caller acts on.
				return array();
		}
	}
}
