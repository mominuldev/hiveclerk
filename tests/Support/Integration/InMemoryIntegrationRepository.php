<?php
/**
 * An integration store that lives in an array.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Integration;

use DateTimeImmutable;
use Hiveclerk\Domain\Integration\Integration;
use Hiveclerk\Domain\Integration\IntegrationRepositoryInterface;

/**
 * Enough of the repository for the rotation sweep to walk.
 *
 * The rotator only reads `all()`, `secret()` and writes `storeSecret()`. The
 * rest of the interface is present because the interface requires it, not
 * because anything here calls it.
 *
 * @internal
 */
final class InMemoryIntegrationRepository implements IntegrationRepositoryInterface {

	/**
	 * @var array<int, Integration>
	 */
	private array $rows = array();

	/**
	 * @var array<int, string|null>
	 */
	private array $secrets = array();

	/**
	 * Seed one integration and its stored ciphertext.
	 *
	 * @param int         $id         Row id.
	 * @param string      $provider   Provider slug.
	 * @param string|null $ciphertext Stored secret.
	 * @return void
	 */
	public function add( int $id, string $provider, ?string $ciphertext ): void {
		$this->rows[ $id ]    = new Integration( id: $id, provider: $provider );
		$this->secrets[ $id ] = $ciphertext;
	}

	public function find( int $id ): ?Integration {
		return $this->rows[ $id ] ?? null;
	}

	public function findByProvider( string $provider ): ?Integration {
		foreach ( $this->rows as $row ) {
			if ( $row->provider === $provider ) {
				return $row;
			}
		}

		return null;
	}

	public function all(): array {
		return array_values( $this->rows );
	}

	public function usable(): array {
		return $this->all();
	}

	public function save( Integration $integration ): Integration {
		if ( null !== $integration->id ) {
			$this->rows[ $integration->id ] = $integration;
		}

		return $integration;
	}

	public function delete( int $id ): bool {
		unset( $this->rows[ $id ], $this->secrets[ $id ] );

		return true;
	}

	public function secret( int $id ): ?string {
		return $this->secrets[ $id ] ?? null;
	}

	public function storeSecret( int $id, ?string $ciphertext, ?DateTimeImmutable $expiresAt = null ): void {
		unset( $expiresAt );

		$this->secrets[ $id ] = $ciphertext;
	}
}
