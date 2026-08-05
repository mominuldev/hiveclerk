<?php
/**
 * Model provider identifiers.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

/**
 * The model providers this build can talk to.
 *
 * A closed enum rather than a free string because the identifier reaches a
 * route path, an option key and an audit record. Third-party providers
 * register through the hiveclerk/providers filter and carry their own
 * identifier, which is why ProviderRegistry keys on strings while
 * everything first-party uses this enum.
 */
enum ProviderId: string {

	case Anthropic  = 'anthropic';
	case OpenAi     = 'openai';
	case Google     = 'google';
	case Azure      = 'azure';
	case OpenRouter = 'openrouter';

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Anthropic  => 'Anthropic',
			self::OpenAi     => 'OpenAI',
			self::Google     => 'Google Gemini',
			self::Azure      => 'Azure OpenAI',
			self::OpenRouter => 'OpenRouter',
		};
	}

	/**
	 * Where the customer finds their key.
	 *
	 * @return string
	 */
	public function consoleUrl(): string {
		return match ( $this ) {
			self::Anthropic  => 'https://console.anthropic.com/settings/keys',
			self::OpenAi     => 'https://platform.openai.com/api-keys',
			self::Google     => 'https://aistudio.google.com/apikey',
			self::Azure      => 'https://portal.azure.com/',
			self::OpenRouter => 'https://openrouter.ai/keys',
		};
	}

	/**
	 * The shape a key from this provider takes.
	 *
	 * Used only to catch an obvious paste error before a network round
	 * trip. It is a hint, never an authorisation decision: providers
	 * change their formats and a wrong guess must not lock a customer out
	 * of their own account, so a mismatch warns rather than rejects.
	 *
	 * @return string|null Regular expression, or null when unconstrained.
	 */
	public function keyHint(): ?string {
		return match ( $this ) {
			self::Anthropic  => '^sk-ant-',
			self::OpenAi     => '^sk-',
			self::OpenRouter => '^sk-or-',
			self::Google,
			self::Azure      => null,
		};
	}

	/**
	 * Whether this provider needs an endpoint as well as a key.
	 *
	 * Azure hosts each deployment on a customer-specific resource domain,
	 * so a key alone is not enough to reach it.
	 *
	 * @return bool
	 */
	public function needsEndpoint(): bool {
		return self::Azure === $this;
	}

	/**
	 * Whether this provider can produce embeddings.
	 *
	 * Anthropic does not offer an embedding model, which is why retrieval
	 * pins its own provider independently of the chat provider.
	 *
	 * @return bool
	 */
	public function canEmbed(): bool {
		return self::Anthropic !== $this;
	}

	/**
	 * Every provider identifier.
	 *
	 * @return array<int, self>
	 */
	public static function all(): array {
		return self::cases();
	}
}
