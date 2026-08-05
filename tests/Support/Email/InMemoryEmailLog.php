<?php
/**
 * The send log without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Email;

use Hiveclerk\Domain\Email\EmailLogEntry;
use Hiveclerk\Domain\Email\EmailLogRepositoryInterface;
use Hiveclerk\Domain\Email\SendStatus;
use Hiveclerk\Domain\Shared\Pagination;

/**
 * The send log, in memory.
 *
 * @internal
 */
final class InMemoryEmailLog implements EmailLogRepositoryInterface {

	/**
	 * Every appended row, in order.
	 *
	 * @var array<int, EmailLogEntry>
	 */
	public array $rows = array();

	public function append( EmailLogEntry $entry ): EmailLogEntry {
		$entry->id    = count( $this->rows ) + 1;
		$this->rows[] = $entry;

		return $entry;
	}

	public function paginate( Pagination $pagination, ?int $leadId = null, ?SendStatus $status = null ): array {
		unset( $pagination, $leadId, $status );

		return $this->rows;
	}

	public function countMatching( ?int $leadId = null, ?SendStatus $status = null ): int {
		unset( $leadId, $status );

		return count( $this->rows );
	}

	public function sentSince( string $since ): int {
		unset( $since );

		return count(
			array_filter(
				$this->rows,
				static fn ( EmailLogEntry $entry ): bool => SendStatus::Sent === $entry->status
			)
		);
	}

	public function alreadySent( int $enrollmentId, int $stepId ): bool {
		foreach ( $this->rows as $entry ) {
			$counted = SendStatus::Sent === $entry->status || SendStatus::Suppressed === $entry->status;

			if ( $counted && $entry->enrollmentId === $enrollmentId && $entry->stepId === $stepId ) {
				return true;
			}
		}

		return false;
	}

	public function statsFor( int $sequenceId ): array {
		unset( $sequenceId );

		return array();
	}
}
