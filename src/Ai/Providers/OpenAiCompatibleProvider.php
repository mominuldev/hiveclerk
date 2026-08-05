<?php
/**
 * Shared behaviour for OpenAI-shaped APIs.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai\Providers;

use Hiveclerk\Ai\Completion;
use Hiveclerk\Ai\CompletionRequest;
use Hiveclerk\Ai\StreamEvent;
use Hiveclerk\Ai\Streaming\SseFrame;
use Hiveclerk\Ai\Streaming\StreamState;

/**
 * The `/chat/completions` request and response shape.
 *
 * Three of the five providers speak it: OpenAI itself, Azure hosting
 * OpenAI models, and OpenRouter proxying everyone else. They differ in
 * their URLs, their auth headers and one field name — which is a thin
 * enough difference that giving each its own copy of the frame parser
 * would be three places to get streaming wrong instead of one.
 */
abstract class OpenAiCompatibleProvider extends AbstractProvider {

	/**
	 * Which field carries the output ceiling.
	 *
	 * OpenAI replaced `max_tokens` with `max_completion_tokens` and the
	 * newer models reject the old name outright. OpenRouter proxies
	 * models from providers that never made that change and still expects
	 * `max_tokens`, so this is a genuine per-provider difference rather
	 * than a version to standardise on.
	 *
	 * @return string
	 */
	protected function maxTokensField(): string {
		return 'max_completion_tokens';
	}

	/**
	 * Whether to ask for usage figures on the final stream chunk.
	 *
	 * Off by default in the API. Without it a streamed reply reports no
	 * tokens at all, and every streamed conversation would be missing
	 * from the cost report.
	 *
	 * @return bool
	 */
	protected function requestsStreamUsage(): bool {
		return true;
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

		if ( '' !== $request->system ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $request->system,
			);
		}

		foreach ( $request->turns as $turn ) {
			$messages[] = array(
				'role'    => $turn->isUser() ? 'user' : 'assistant',
				'content' => $turn->content,
			);
		}

		$payload = array(
			'model'                 => $request->model,
			'messages'              => $messages,
			'temperature'           => $request->temperature,
			'stream'                => $stream,
			$this->maxTokensField() => $request->maxTokens,
		);

		if ( array() !== $request->stop ) {
			$payload['stop'] = array_values( $request->stop );
		}

		if ( $stream && $this->requestsStreamUsage() ) {
			$payload['stream_options'] = array( 'include_usage' => true );
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
		$choices = $json['choices'] ?? array();
		$choice  = is_array( $choices ) && isset( $choices[0] ) && is_array( $choices[0] )
			? $choices[0]
			: array();

		$message = $choice['message'] ?? array();
		$text    = is_array( $message ) ? self::stringAt( $message, 'content' ) : '';

		return new Completion(
			text: $text,
			model: self::stringAt( $json, 'model', $request->model ),
			provider: $this->id(),
			tokensIn: self::intAt( $json, 'usage', 'prompt_tokens' ),
			tokensOut: self::intAt( $json, 'usage', 'completion_tokens' ),
			finishReason: self::stringAt( $choice, 'finish_reason', 'stop' ),
			reportedCost: $this->reportedCost( $json )
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
		if ( $frame->isDoneSentinel() ) {
			$state->finished = true;

			return array( StreamEvent::done( $state->toCompletion() ) );
		}

		$data = $frame->json();

		if ( array() === $data ) {
			return array();
		}

		if ( isset( $data['error'] ) ) {
			$error   = $data['error'];
			$message = is_array( $error )
				? self::stringAt( $error, 'message', 'The provider reported an error.' )
				: 'The provider reported an error.';

			$state->finished = true;

			return array( StreamEvent::error( $message, true ) );
		}

		$state->model = self::stringAt( $data, 'model', $state->model );

		/*
		 * The usage chunk arrives last and carries an empty choices array.
		 * Reading usage before returning on an empty choices list is what
		 * keeps streamed replies out of the "unpriced" bucket.
		 */
		if ( isset( $data['usage'] ) && is_array( $data['usage'] ) ) {
			$state->tokensIn  = self::intAt( $data, 'usage', 'prompt_tokens' );
			$state->tokensOut = self::intAt( $data, 'usage', 'completion_tokens' );
		}

		$choices = $data['choices'] ?? array();

		if ( ! is_array( $choices ) || ! isset( $choices[0] ) || ! is_array( $choices[0] ) ) {
			return array();
		}

		$choice = $choices[0];
		$reason = $choice['finish_reason'] ?? null;

		if ( is_string( $reason ) && '' !== $reason ) {
			$state->finishReason = $reason;
		}

		$delta = $choice['delta'] ?? array();
		$text  = is_array( $delta ) ? self::stringAt( $delta, 'content' ) : '';

		if ( '' === $text ) {
			return array();
		}

		$state->append( $text );

		return array( StreamEvent::delta( $text ) );
	}

	/**
	 * Cost the provider reported for itself, when it does.
	 *
	 * Only OpenRouter does; the base implementation exists so the shared
	 * parser does not need to know which subclass it is running as.
	 *
	 * @param array<string, mixed> $json Decoded body.
	 * @return float|null
	 */
	protected function reportedCost( array $json ): ?float {
		unset( $json );

		return null;
	}
}
