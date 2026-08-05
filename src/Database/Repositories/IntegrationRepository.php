<?php
/**
 * Integration repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use DateTimeImmutable;
use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Integration\FieldMap;
use Hiveclerk\Domain\Integration\Integration;
use Hiveclerk\Domain\Integration\IntegrationRepositoryInterface;
use Hiveclerk\Domain\Integration\IntegrationStatus;

/**
 * Stores configured connections.
 *
 * The `credentials` column is never selected into an entity. `find()` and
 * friends build an Integration that has nowhere to put a token, and the
 * two secret methods read and write that one column on their own.
 */
final class IntegrationRepository extends AbstractRepository implements IntegrationRepositoryInterface {

	protected function table(): string {
		return Schema::INTEGRATIONS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'provider', 'status', 'last_sync_at', 'created_at' );
	}

	public function find( int $id ): ?Integration {
		$row = $this->fetchRow( 'id = %d', array( $id ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function findByProvider( string $provider ): ?Integration {
		$row = $this->fetchRow( 'provider = %s', array( $provider ) );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function all(): array {
		return array_map(
			fn ( array $row ): Integration => $this->hydrate( $row ),
			$this->fetchAll( '1=1', array(), 'id', 'ASC' )
		);
	}

	public function usable(): array {
		return array_map(
			fn ( array $row ): Integration => $this->hydrate( $row ),
			$this->fetchAll(
				'status IN ( %s, %s )',
				array( IntegrationStatus::Connected->value, IntegrationStatus::Degraded->value ),
				'id',
				'ASC'
			)
		);
	}

	public function save( Integration $integration ): Integration {
		$data = array(
			'provider'      => $integration->provider,
			'name'          => $integration->name,
			'status'        => $integration->status->value,
			'field_mapping' => $this->encodeJson( $integration->fieldMap->toArray() ),
			'sync_config'   => $this->encodeJson( $integration->syncConfig ),
			'last_sync_at'  => $this->stamp( $integration->lastSyncAt ),
			'last_error'    => $integration->lastError,
			'error_count'   => $integration->errorCount,
			'updated_at'    => $this->now(),
		);

		if ( null === $integration->id ) {
			$data['created_at'] = $this->now();

			$id = $this->insertRow( $data );

			if ( null === $id ) {
				return $integration;
			}

			$integration->id = $id;

			return $integration;
		}

		$this->updateRow( $integration->id, $data );

		return $integration;
	}

	public function delete( int $id ): bool {
		return $this->deleteRow( $id );
	}

	public function secret( int $id ): ?string {
		$row = $this->fetchRow( 'id = %d', array( $id ) );

		return null === $row ? null : $this->text( $row['credentials'] ?? null );
	}

	public function storeSecret( int $id, ?string $ciphertext, ?DateTimeImmutable $expiresAt = null ): void {
		$this->updateRow(
			$id,
			array(
				'credentials'      => $ciphertext,
				'token_expires_at' => $this->stamp( $expiresAt ),
				'updated_at'       => $this->now(),
			)
		);
	}

	/**
	 * Build an Integration from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return Integration
	 */
	private function hydrate( array $row ): Integration {
		return new Integration(
			id: (int) $row['id'],
			provider: (string) $row['provider'],
			name: $this->text( $row['name'] ?? null ),
			status: IntegrationStatus::fromStorage( $this->text( $row['status'] ?? null ) ),
			tokenExpiresAt: $this->time( $row['token_expires_at'] ?? null ),
			fieldMap: FieldMap::fromArray( $this->json( $row['field_mapping'] ?? null ) ),
			syncConfig: $this->json( $row['sync_config'] ?? null ),
			lastSyncAt: $this->time( $row['last_sync_at'] ?? null ),
			lastError: $this->text( $row['last_error'] ?? null ),
			errorCount: (int) ( $row['error_count'] ?? 0 ),
			createdAt: $this->time( $row['created_at'] ?? null ),
			updatedAt: $this->time( $row['updated_at'] ?? null ),
		);
	}
}
