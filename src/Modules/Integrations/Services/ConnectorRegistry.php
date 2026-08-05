<?php
/**
 * Connector registry.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Integrations\Services;

use Hiveclerk\Domain\Integration\CrmConnectorInterface;

/**
 * Every connector this site knows about.
 *
 * The `hiveclerk/crm/connectors` filter documented in D9 §7 runs once, on
 * first read, and the result is memoised. A third-party connector added
 * through it is indistinguishable from a built-in one everywhere else in
 * the module — same grid card, same mapping screen, same retry policy —
 * which is the foundation the V3 marketplace is supposed to stand on.
 *
 * Anything the filter returns that is not a connector is dropped rather
 * than fataling. A plugin that registers a string here should break its
 * own integration, not the customer's Integrations screen.
 */
final class ConnectorRegistry {

	/**
	 * Connectors by id, once resolved.
	 *
	 * @var array<string, CrmConnectorInterface>|null
	 */
	private ?array $connectors = null;

	/**
	 * Construct.
	 *
	 * @param array<int, CrmConnectorInterface> $builtIn Connectors shipped with the plugin.
	 */
	public function __construct( private readonly array $builtIn ) {
	}

	/**
	 * Every connector, keyed by id.
	 *
	 * @return array<string, CrmConnectorInterface>
	 */
	public function all(): array {
		if ( null !== $this->connectors ) {
			return $this->connectors;
		}

		$connectors = array();

		foreach ( $this->builtIn as $connector ) {
			$connectors[ $connector->id() ] = $connector;
		}

		/**
		 * Register a CRM connector.
		 *
		 * @param array<string, CrmConnectorInterface> $connectors Connectors by id.
		 */
		$filtered = apply_filters( 'hiveclerk/crm/connectors', $connectors );

		if ( ! is_array( $filtered ) ) {
			$this->connectors = $connectors;

			return $this->connectors;
		}

		$clean = array();

		foreach ( $filtered as $connector ) {
			if ( $connector instanceof CrmConnectorInterface ) {
				$clean[ $connector->id() ] = $connector;
			}
		}

		$this->connectors = $clean;

		return $this->connectors;
	}

	/**
	 * One connector by id.
	 *
	 * @param string $id Connector identifier.
	 * @return CrmConnectorInterface|null
	 */
	public function get( string $id ): ?CrmConnectorInterface {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Whether an id names a connector this site has.
	 *
	 * @param string $id Connector identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return null !== $this->get( $id );
	}
}
