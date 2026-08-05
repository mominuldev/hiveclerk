<?php
/**
 * Audit storage without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Chat;

use Hiveclerk\Domain\Audit\AuditEntry;
use Hiveclerk\Domain\Audit\AuditRepositoryInterface;
use Hiveclerk\Domain\Shared\Pagination;

/**
 * Audit storage without a database.
 *
 * @internal
 */
final class InMemoryAudit implements AuditRepositoryInterface {

	/**
	 * Entries in write order.
	 *
	 * @var array<int, AuditEntry>
	 */
	public array $entries = array();

	public function append( AuditEntry $entry ): void {
		$this->entries[] = $entry;
	}

	public function paginate(
		Pagination $pagination,
		?string $action = null,
		?int $userId = null
	): array {
		return $this->entries;
	}

	public function total( ?string $action = null, ?int $userId = null ): int {
		return count( $this->entries );
	}

	public function actions(): array {
		return array_values(
			array_unique(
				array_map( static fn ( AuditEntry $entry ): string => $entry->action, $this->entries )
			)
		);
	}

	public function purgeBefore( string $before ): int {
		return 0;
	}

	/**
	 * The action names recorded so far.
	 *
	 * @return array<int, string>
	 */
	public function recorded(): array {
		return array_map( static fn ( AuditEntry $entry ): string => $entry->action, $this->entries );
	}
}
