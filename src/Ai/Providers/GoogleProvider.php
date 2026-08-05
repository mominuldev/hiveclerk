<?php
/**
 * Google Gemini adapter.
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
 * Talks to the Gemini generative language API.
 *
 * The furthest of the five from the OpenAI shape. Messages are `contents`
 * with `parts`, the assistant role is called "model", the operation is
 * part of the URL rather than a flag in the body, and the streaming
 * endpoint returns a JSON array unless `alt=sse` is asked for explicitly.
 *
 * The key goes in a header rather than the query string that Google's own
 * examples use. A key in a URL ends up in access logs, in proxy logs, and
 * in any Referer header the request produces — three copies of the
 * customer's credential written to disk by systems we do not control.
 */
final class GoogleProvider extends AbstractProvider {

	private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

	/**
	 * Identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return ProviderId::Google->value;
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function label(): string {
		return ProviderId::Google->label();
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
		return 'gemini-2.5-flash';
	}

	/**
	 * Models this key can reach.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return array<int, Model>
	 */
	public function models( Credentials $credentials ): array {
		$json = $this->send( $credentials, 'GET', self::BASE . '/models?pageSize=200' );
		$data = $json['models'] ?? array();

		if ( ! is_array( $data ) ) {
			return array();
		}

		$models = array();

		foreach ( $data as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$methods = $entry['supportedGenerationMethods'] ?? array();

			// Embedding and token-counting models appear in the same list.
			// Filtering on the declared method is more durable than
			// pattern-matching names Google reuses across families.
			if ( ! is_array( $methods ) || ! in_array( 'generateContent', $methods, true ) ) {
				continue;
			}

			// Names arrive fully qualified as "models/gemini-2.5-pro" but
			// every other field in the API expects the bare id.
			$id = self::shortName( self::stringAt( $entry, 'name' ) );

			if ( '' === $id ) {
				continue;
			}

			$input  = $entry['inputTokenLimit'] ?? 0;
			$output = $entry['outputTokenLimit'] ?? 0;

			$models[] = new Model(
				id: $id,
				label: self::stringAt( $entry, 'displayName', $id ),
				contextWindow: is_numeric( $input ) ? (int) $input : 0,
				maxOutput: is_numeric( $output ) ? (int) $output : 0,
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
		unset( $credentials );

		$model = rawurlencode( self::shortName( $request->model ) );

		// Without alt=sse the streaming endpoint returns a progressively
		// written JSON array, which cannot be parsed until it closes —
		// technically a stream, practically a slow buffered response.
		return $stream
			? sprintf( '%s/models/%s:streamGenerateContent?alt=sse', self::BASE, $model )
			: sprintf( '%s/models/%s:generateContent', self::BASE, $model );
	}

	/**
	 * Headers.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return array<string, string>
	 */
	protected function headers( Credentials $credentials ): array {
		return array(
			'x-goog-api-key' => $credentials->apiKey,
			'accept'         => 'application/json',
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
		// Streaming is selected by the URL, not the body.
		unset( $stream );

		$contents = array();

		foreach ( $request->turns as $turn ) {
			$contents[] = array(
				'role'  => $turn->isUser() ? 'user' : 'model',
				'parts' => array( array( 'text' => $turn->content ) ),
			);
		}

		$config = array(
			'temperature'     => $request->temperature,
			'maxOutputTokens' => $request->maxTokens,
		);

		if ( array() !== $request->stop ) {
			$config['stopSequences'] = array_values( $request->stop );
		}

		$payload = array(
			'contents'         => $contents,
			'generationConfig' => $config,
		);

		if ( '' !== $request->system ) {
			$payload['systemInstruction'] = array(
				'parts' => array( array( 'text' => $request->system ) ),
			);
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
		$candidate = self::firstCandidate( $json );

		return new Completion(
			text: self::candidateText( $candidate ),
			model: self::stringAt( $json, 'modelVersion', $request->model ),
			provider: $this->id(),
			tokensIn: self::intAt( $json, 'usageMetadata', 'promptTokenCount' ),
			tokensOut: self::intAt( $json, 'usageMetadata', 'candidatesTokenCount' ),
			finishReason: self::finishReason( $candidate )
		);
	}

	/**
	 * Translate a stream frame.
	 *
	 * Each frame is a whole GenerateContentResponse rather than a delta
	 * type of its own, and usage is repeated on every one — so the last
	 * frame's figures are the final figures, and overwriting each time is
	 * correct rather than lossy.
	 *
	 * @param SseFrame    $frame Frame.
	 * @param StreamState $state Running state.
	 * @return array<int, StreamEvent>
	 */
	protected function translate( SseFrame $frame, StreamState $state ): array {
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

		$state->model = self::stringAt( $data, 'modelVersion', $state->model );

		if ( isset( $data['usageMetadata'] ) ) {
			$state->tokensIn  = self::intAt( $data, 'usageMetadata', 'promptTokenCount' );
			$state->tokensOut = self::intAt( $data, 'usageMetadata', 'candidatesTokenCount' );
		}

		$candidate = self::firstCandidate( $data );
		$reason    = $candidate['finishReason'] ?? null;
		$text      = self::candidateText( $candidate );

		$events = array();

		if ( '' !== $text ) {
			$state->append( $text );
			$events[] = StreamEvent::delta( $text );
		}

		// A finish reason is only present on the last frame, which makes
		// it the terminator: Gemini sends no [DONE] sentinel.
		if ( is_string( $reason ) && '' !== $reason ) {
			$state->finishReason = self::normaliseReason( $reason );
			$state->finished     = true;
			$events[]            = StreamEvent::done( $state->toCompletion() );
		}

		return $events;
	}

	/**
	 * The first candidate in a response.
	 *
	 * Only one is ever requested. A response with none is a safety block,
	 * which surfaces as an empty reply carrying its finish reason rather
	 * than as an error — the caller decides what to say about it.
	 *
	 * @param array<string, mixed> $json Response.
	 * @return array<string, mixed>
	 */
	private static function firstCandidate( array $json ): array {
		$candidates = $json['candidates'] ?? array();

		if ( ! is_array( $candidates ) || ! isset( $candidates[0] ) || ! is_array( $candidates[0] ) ) {
			return array();
		}

		return $candidates[0];
	}

	/**
	 * Concatenate the text parts of a candidate.
	 *
	 * @param array<string, mixed> $candidate Candidate.
	 * @return string
	 */
	private static function candidateText( array $candidate ): string {
		$content = $candidate['content'] ?? array();

		if ( ! is_array( $content ) ) {
			return '';
		}

		$parts = $content['parts'] ?? array();

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$text = '';

		foreach ( $parts as $part ) {
			if ( is_array( $part ) ) {
				$text .= self::stringAt( $part, 'text' );
			}
		}

		return $text;
	}

	/**
	 * Finish reason for a candidate, normalised.
	 *
	 * @param array<string, mixed> $candidate Candidate.
	 * @return string
	 */
	private static function finishReason( array $candidate ): string {
		return self::normaliseReason( self::stringAt( $candidate, 'finishReason', 'STOP' ) );
	}

	/**
	 * Lower-case Google's SCREAMING_CASE reasons.
	 *
	 * MAX_TOKENS becomes max_tokens, which is the value Completion checks
	 * for truncation. Leaving it upper-case would silently break the
	 * truncation metric on one provider out of five.
	 *
	 * @param string $reason Raw reason.
	 * @return string
	 */
	private static function normaliseReason( string $reason ): string {
		return strtolower( $reason );
	}

	/**
	 * Strip the "models/" prefix Google puts on resource names.
	 *
	 * @param string $name Possibly qualified name.
	 * @return string
	 */
	private static function shortName( string $name ): string {
		return str_starts_with( $name, 'models/' ) ? substr( $name, 7 ) : $name;
	}
}
