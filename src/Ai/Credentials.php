<?php
/**
 * Provider credentials.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

use JsonSerializable;
use SensitiveParameter;

/**
 * What is needed to authenticate against one provider.
 *
 * A value object rather than a bare string because Azure needs a resource
 * endpoint and an API version alongside the key, and threading three
 * loose parameters through every adapter method invites getting the order
 * wrong exactly once, in the argument that happens to be the secret.
 *
 * Marked non-serialisable on purpose: __sleep() throwing means a
 * credential can never end up in a transient, a queued job payload or a
 * debug log through an accidental serialize().
 */
final class Credentials implements JsonSerializable {

	/**
	 * Construct.
	 *
	 * @param string $apiKey     Secret key.
	 * @param string $endpoint   Base URL, for self-hosted or Azure.
	 * @param string $apiVersion API version, Azure only.
	 */
	public function __construct(
		#[SensitiveParameter]
		public readonly string $apiKey,
		public readonly string $endpoint = '',
		public readonly string $apiVersion = ''
	) {
	}

	/**
	 * Empty credentials, for an unconfigured provider.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self( '' );
	}

	/**
	 * Whether a key is present.
	 *
	 * @return bool
	 */
	public function isPresent(): bool {
		return '' !== trim( $this->apiKey );
	}

	/**
	 * Refuse to be serialised.
	 *
	 * @return array<int, string>
	 *
	 * @throws \LogicException Always.
	 */
	public function __sleep(): array {
		throw new \LogicException( 'Credentials must not be serialised.' );
	}

	/**
	 * Refuse to be JSON encoded, for the same reason.
	 *
	 * `__sleep()` covers `serialize()` and everything built on it — a
	 * transient, a job payload, a cached option. It does nothing for
	 * `wp_json_encode()`, which reads the public properties directly, so
	 * one of these reaching a REST response or a debug log would have
	 * carried the key out in plain text. The properties are public because
	 * the class is a value object; this is what stops that being a hole.
	 *
	 * @return array<string, string>
	 *
	 * @throws \LogicException Always.
	 */
	public function jsonSerialize(): array {
		throw new \LogicException( 'Credentials must not be encoded as JSON.' );
	}

	/**
	 * Keep the secret out of var_dump and stack traces.
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array(
			'apiKey'   => $this->isPresent() ? '[redacted]' : '[unset]',
			'endpoint' => $this->endpoint,
		);
	}
}
