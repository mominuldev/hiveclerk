<?php
/**
 * Provider lookup.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

/**
 * Holds the available providers and lets third parties add more.
 *
 * The filter is the extension point promised in the API specification:
 * registering a model provider is one add_filter call, which is what
 * makes the V3 marketplace a distribution problem rather than a code
 * problem. Anything the filter returns that is not an actual provider is
 * dropped rather than trusted — a plugin conflict should degrade to "that
 * provider is missing", not to a fatal error on every admin page.
 */
final class ProviderRegistry {

	/**
	 * Providers by identifier.
	 *
	 * @var array<string, LlmProviderInterface>
	 */
	private array $providers = array();

	/**
	 * Whether the filter has run.
	 *
	 * @var bool
	 */
	private bool $extended = false;

	/**
	 * Add a provider.
	 *
	 * @param LlmProviderInterface $provider Provider.
	 * @return void
	 */
	public function add( LlmProviderInterface $provider ): void {
		$this->providers[ $provider->id() ] = $provider;
	}

	/**
	 * Whether a provider is available.
	 *
	 * @param string $id Identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->all()[ $id ] );
	}

	/**
	 * Look up a provider.
	 *
	 * @param string $id Identifier.
	 * @return LlmProviderInterface
	 *
	 * @throws ProviderException When no such provider is registered.
	 */
	public function get( string $id ): LlmProviderInterface {
		$providers = $this->all();

		if ( ! isset( $providers[ $id ] ) ) {
			throw new ProviderException(
				sprintf( '"%s" is not an available model provider.', $id ),
				$id,
				404
			);
		}

		return $providers[ $id ];
	}

	/**
	 * Every available provider.
	 *
	 * @return array<string, LlmProviderInterface>
	 */
	public function all(): array {
		if ( $this->extended ) {
			return $this->providers;
		}

		$this->extended = true;

		/**
		 * Register additional model providers.
		 *
		 * @param array<string, LlmProviderInterface> $providers Keyed by identifier.
		 */
		$filtered = apply_filters( 'hiveclerk/providers', $this->providers );

		if ( ! is_array( $filtered ) ) {
			return $this->providers;
		}

		$valid = array();

		foreach ( $filtered as $id => $provider ) {
			if ( is_string( $id ) && $provider instanceof LlmProviderInterface ) {
				$valid[ $id ] = $provider;
			}
		}

		$this->providers = $valid;

		return $this->providers;
	}

	/**
	 * Identifiers of every available provider.
	 *
	 * @return array<int, string>
	 */
	public function ids(): array {
		return array_keys( $this->all() );
	}
}
