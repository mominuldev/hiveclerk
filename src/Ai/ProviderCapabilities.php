<?php
/**
 * What a provider supports.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

/**
 * Capability flags for one provider.
 *
 * Read by the UI to decide which controls to show, and by the services to
 * decide which code path to take. A flag here is always about the
 * provider, never about the host — whether *this server* can stream is
 * the HTTP client's question, and conflating the two produced a bug class
 * we would rather not have twice.
 */
final class ProviderCapabilities implements \JsonSerializable {

	/**
	 * Construct.
	 *
	 * @param bool $streaming    Supports token streaming.
	 * @param bool $embeddings   Offers an embedding model.
	 * @param bool $systemPrompt Accepts a separate system instruction.
	 * @param bool $liveModels   Exposes a model list endpoint.
	 * @param bool $needsEndpoint Requires a customer-specific base URL.
	 */
	public function __construct(
		public readonly bool $streaming = true,
		public readonly bool $embeddings = true,
		public readonly bool $systemPrompt = true,
		public readonly bool $liveModels = true,
		public readonly bool $needsEndpoint = false
	) {
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, bool>
	 */
	public function jsonSerialize(): array {
		return array(
			'streaming'      => $this->streaming,
			'embeddings'     => $this->embeddings,
			'system_prompt'  => $this->systemPrompt,
			'live_models'    => $this->liveModels,
			'needs_endpoint' => $this->needsEndpoint,
		);
	}
}
