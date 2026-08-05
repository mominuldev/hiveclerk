<?php
/**
 * OpenAI adapter.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai\Providers;

use Hiveclerk\Ai\CompletionRequest;
use Hiveclerk\Ai\Credentials;
use Hiveclerk\Ai\Model;
use Hiveclerk\Ai\ProviderCapabilities;
use Hiveclerk\Ai\ProviderId;

/**
 * Talks to the OpenAI chat completions API.
 */
final class OpenAiProvider extends OpenAiCompatibleProvider {

	private const BASE = 'https://api.openai.com/v1';

	/**
	 * Model families worth offering for chat.
	 *
	 * The /models list returns everything the account can see, including
	 * moderation, audio, image and fine-tuning artefacts. Presenting all
	 * of it in a chat model picker would be a list of two hundred entries
	 * where the wrong choice fails at the first message, so the list is
	 * narrowed to families that serve chat completions.
	 */
	private const CHAT_PREFIXES = array( 'gpt-', 'o1', 'o3', 'o4', 'chatgpt-' );

	/**
	 * Identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return ProviderId::OpenAi->value;
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function label(): string {
		return ProviderId::OpenAi->label();
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
		return 'gpt-5-mini';
	}

	/**
	 * Models this key can reach.
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

			if ( '' === $id || ! self::isChatModel( $id ) ) {
				continue;
			}

			$models[] = new Model(
				id: $id,
				label: $id,
				pricing: $this->pricing( $id )
			);
		}

		usort( $models, static fn ( Model $a, Model $b ): int => strcmp( $a->id, $b->id ) );

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
		unset( $request, $stream );

		$base = '' !== $credentials->endpoint
			? rtrim( $credentials->endpoint, '/' )
			: self::BASE;

		return $base . '/chat/completions';
	}

	/**
	 * Headers.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return array<string, string>
	 */
	protected function headers( Credentials $credentials ): array {
		return array(
			'Authorization' => 'Bearer ' . $credentials->apiKey,
			'accept'        => 'application/json',
		);
	}

	/**
	 * Whether a model id belongs in the chat picker.
	 *
	 * @param string $id Model identifier.
	 * @return bool
	 */
	private static function isChatModel( string $id ): bool {
		// Multimodal and specialised variants share the gpt- prefix but
		// are not chat endpoints, and selecting one produces a 400 on the
		// first real message rather than at selection time.
		foreach ( array( '-audio', '-realtime', '-search', '-transcribe', '-tts', '-image', 'instruct' ) as $excluded ) {
			if ( str_contains( $id, $excluded ) ) {
				return false;
			}
		}

		foreach ( self::CHAT_PREFIXES as $prefix ) {
			if ( str_starts_with( $id, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
