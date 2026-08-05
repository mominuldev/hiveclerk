<?php
/**
 * Integration storage contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

use DateTimeImmutable;

/**
 * Where connections live.
 *
 * Secrets are read and written through their own two methods rather than
 * riding on the entity. Everything that renders an integration takes an
 * `Integration`, so a token cannot reach a response through a presenter
 * that forgot to unset a property — it was never on the object to unset.
 */
interface IntegrationRepositoryInterface {

	/**
	 * One connection by id.
	 *
	 * @param int $id Storage id.
	 * @return Integration|null
	 */
	public function find( int $id ): ?Integration;

	/**
	 * One connection by connector.
	 *
	 * @param string $provider Connector identifier.
	 * @return Integration|null
	 */
	public function findByProvider( string $provider ): ?Integration;

	/**
	 * Every configured connection.
	 *
	 * @return array<int, Integration>
	 */
	public function all(): array;

	/**
	 * Every connection that could be pushed to.
	 *
	 * @return array<int, Integration>
	 */
	public function usable(): array;

	/**
	 * Insert or update.
	 *
	 * @param Integration $integration Connection.
	 * @return Integration
	 */
	public function save( Integration $integration ): Integration;

	/**
	 * Remove a connection and its stored credentials.
	 *
	 * @param int $id Storage id.
	 * @return bool
	 */
	public function delete( int $id ): bool;

	/**
	 * The stored ciphertext for a connection.
	 *
	 * Returns what is in the column, undecrypted. The credential store
	 * does the decryption, because the key derivation is WordPress-aware
	 * and this layer is not the place for it.
	 *
	 * @param int $id Storage id.
	 * @return string|null
	 */
	public function secret( int $id ): ?string;

	/**
	 * Replace the stored ciphertext.
	 *
	 * @param int                    $id         Storage id.
	 * @param string|null            $ciphertext Encrypted credentials, or null to clear.
	 * @param DateTimeImmutable|null $expiresAt  Token expiry, UTC.
	 * @return void
	 */
	public function storeSecret( int $id, ?string $ciphertext, ?DateTimeImmutable $expiresAt = null ): void;
}
