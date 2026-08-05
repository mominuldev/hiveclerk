<?php
/**
 * The suppression list without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Email;

use Hiveclerk\Domain\Email\SuppressionReason;
use Hiveclerk\Domain\Email\SuppressionRepositoryInterface;
use Hiveclerk\Domain\Shared\Pagination;

/**
 * The suppression list, in memory.
 *
 * @internal
 */
final class InMemorySuppressions implements SuppressionRepositoryInterface {

	/**
	 * Suppressed hashes to their reason.
	 *
	 * @var array<string, string>
	 */
	public array $rows = array();

	public function isSuppressed( string $emailHash ): bool {
		return isset( $this->rows[ $emailHash ] );
	}

	public function suppress( string $emailHash, SuppressionReason $reason ): void {
		// Insert-ignore semantics: the first reason wins, exactly as the
		// unique index makes it in MySQL.
		if ( ! isset( $this->rows[ $emailHash ] ) ) {
			$this->rows[ $emailHash ] = $reason->value;
		}
	}

	public function release( string $emailHash ): bool {
		unset( $this->rows[ $emailHash ] );

		return true;
	}

	public function countAll(): int {
		return count( $this->rows );
	}

	public function paginate( Pagination $pagination ): array {
		unset( $pagination );

		$rows = array();

		foreach ( $this->rows as $hash => $reason ) {
			$rows[] = array(
				'email_hash' => $hash,
				'reason'     => $reason,
				'created_at' => null,
			);
		}

		return $rows;
	}
}
