<?php
/**
 * Model descriptor.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

/**
 * One model offered by a provider.
 *
 * Carries only what the product actually decides with: what to show in the
 * picker, whether a prompt will fit, and what it costs.
 */
final class Model implements \JsonSerializable {

	/**
	 * Construct.
	 *
	 * @param string       $id            Provider-native model identifier.
	 * @param string       $label         Display name.
	 * @param int          $contextWindow Total token window, 0 when unknown.
	 * @param int          $maxOutput     Maximum output tokens, 0 when unknown.
	 * @param Pricing|null $pricing       Cost, or null when not published.
	 * @param bool         $streams       Whether it supports token streaming.
	 * @param bool         $embeds        Whether it is an embedding model.
	 * @param int          $dimensions    Embedding width, 0 for chat models.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly int $contextWindow = 0,
		public readonly int $maxOutput = 0,
		public readonly ?Pricing $pricing = null,
		public readonly bool $streams = true,
		public readonly bool $embeds = false,
		public readonly int $dimensions = 0
	) {
	}

	/**
	 * An embedding model descriptor.
	 *
	 * @param string       $id         Model identifier.
	 * @param string       $label      Display name.
	 * @param int          $dimensions Vector width.
	 * @param Pricing|null $pricing    Cost.
	 * @return self
	 */
	public static function embedding(
		string $id,
		string $label,
		int $dimensions,
		?Pricing $pricing = null
	): self {
		return new self(
			id: $id,
			label: $label,
			pricing: $pricing,
			streams: false,
			embeds: true,
			dimensions: $dimensions
		);
	}

	/**
	 * Wire form for the model picker.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'id'             => $this->id,
			'label'          => $this->label,
			'context_window' => $this->contextWindow,
			'max_output'     => $this->maxOutput,
			'streams'        => $this->streams,
			'embeds'         => $this->embeds,
			'dimensions'     => $this->dimensions,
			'pricing'        => $this->pricing?->jsonSerialize(),
		);
	}
}
