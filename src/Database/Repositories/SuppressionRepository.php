<?php
/**
 * Suppression repository.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Repositories;

use Hiveclerk\Database\AbstractRepository;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Email\SuppressionReason;
use Hiveclerk\Domain\Email\SuppressionRepositoryInterface;
use Hiveclerk\Domain\Shared\Pagination;

/**
 * Stores addresses that are never written to again.
 */
final class SuppressionRepository extends AbstractRepository implements SuppressionRepositoryInterface {

	protected function table(): string {
		return Schema::SUPPRESSIONS;
	}

	protected function sortableColumns(): array {
		return array( 'id', 'created_at' );
	}

	public function isSuppressed( string $emailHash ): bool {
		return $this->countWhere( 'email_hash = %s', array( $emailHash ) ) > 0;
	}

	public function suppress( string $emailHash, SuppressionReason $reason ): void {
		$table = $this->tableName();

		// INSERT IGNORE against the unique index rather than a check-then-
		// insert. Two unsubscribe clicks a second apart — which is exactly
		// what an impatient recipient does — would otherwise race, and the
		// loser would surface as a database error on a page whose whole
		// job is to say "done".
		$this->execute(
			"INSERT IGNORE INTO `{$table}` ( email_hash, reason, created_at ) VALUES ( %s, %s, %s )",
			array( $emailHash, $reason->value, $this->now() )
		);
	}

	public function release( string $emailHash ): bool {
		$table = $this->tableName();

		return $this->execute(
			"DELETE FROM `{$table}` WHERE email_hash = %s",
			array( $emailHash )
		);
	}

	public function countAll(): int {
		return $this->countWhere();
	}

	public function paginate( Pagination $pagination ): array {
		$rows = $this->fetchAll( '1=1', array(), 'id', 'DESC', $pagination->perPage, $pagination->offset() );

		return array_map(
			fn ( array $row ): array => array(
				'email_hash' => (string) $row['email_hash'],
				'reason'     => SuppressionReason::fromStorage( $this->text( $row['reason'] ?? null ) )->value,
				'created_at' => ( $this->time( $row['created_at'] ?? null ) )?->format( 'c' ),
			),
			$rows
		);
	}
}
