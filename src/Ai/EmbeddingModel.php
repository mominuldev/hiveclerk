<?php
/**
 * A pinned embedding model.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

/**
 * Which model a body of content was — or will be — embedded with.
 *
 * Pinned per source rather than read from settings at query time. The
 * alternative fails quietly and completely: an operator switches provider
 * in settings, every existing vector is now from a different space, and
 * retrieval returns confident nonsense rather than an error. Recording
 * the pin is what lets the mismatch be *detected* and turned into a
 * re-index offer.
 */
final class EmbeddingModel implements \JsonSerializable {

	/**
	 * Construct.
	 *
	 * @param string $provider   Provider identifier.
	 * @param string $model      Model identifier.
	 * @param int    $dimensions Vector width, 0 when not yet known.
	 */
	public function __construct(
		public readonly string $provider,
		public readonly string $model,
		public readonly int $dimensions = 0
	) {
	}

	/**
	 * Whether both halves are present.
	 *
	 * @return bool
	 */
	public function isComplete(): bool {
		return '' !== trim( $this->provider ) && '' !== trim( $this->model );
	}

	/**
	 * Whether this is the same model as another pin.
	 *
	 * Dimensions are excluded from the comparison on purpose: a provider
	 * that changes a model's width without changing its name has broken
	 * the pin in a way a name check cannot see, and that case is caught
	 * where the vectors are written, against the actual stored width.
	 *
	 * @param self|null $other Other pin.
	 * @return bool
	 */
	public function is( ?self $other ): bool {
		return null !== $other
			&& $this->provider === $other->provider
			&& $this->model === $other->model;
	}

	/**
	 * The same pin with a measured width.
	 *
	 * @param int $dimensions Width.
	 * @return self
	 */
	public function withDimensions( int $dimensions ): self {
		return new self( $this->provider, $this->model, $dimensions );
	}

	/**
	 * Build from stored values, or null when either half is missing.
	 *
	 * @param string|null $provider   Provider.
	 * @param string|null $model      Model.
	 * @param int|null    $dimensions Width.
	 * @return self|null
	 */
	public static function fromStorage( ?string $provider, ?string $model, ?int $dimensions = null ): ?self {
		if ( null === $provider || null === $model || '' === trim( $provider ) || '' === trim( $model ) ) {
			return null;
		}

		return new self( $provider, $model, max( 0, (int) $dimensions ) );
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'provider'   => $this->provider,
			'model'      => $this->model,
			'dimensions' => $this->dimensions,
		);
	}
}
