<?php
/**
 * The run log without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Workflows;

use Hiveclerk\Domain\Workflow\RunLogEntry;
use Hiveclerk\Domain\Workflow\RunLogRepositoryInterface;

/**
 * Run log lines, in memory.
 *
 * @internal
 */
final class InMemoryRunLog implements RunLogRepositoryInterface {

	/**
	 * Every line appended, in order.
	 *
	 * @var array<int, RunLogEntry>
	 */
	public array $entries = array();

	public function append( RunLogEntry $entry ): void {
		$this->entries[] = $entry;
	}

	public function forRun( int $runId, int $limit = 200 ): array {
		return array_slice(
			array_values(
				array_filter(
					$this->entries,
					static fn ( RunLogEntry $entry ): bool => $entry->runId === $runId
				)
			),
			0,
			$limit
		);
	}

	public function deleteForRuns( array $runIds ): int {
		$before = count( $this->entries );

		$this->entries = array_values(
			array_filter(
				$this->entries,
				static fn ( RunLogEntry $entry ): bool => ! in_array( $entry->runId, $runIds, true )
			)
		);

		return $before - count( $this->entries );
	}

	/**
	 * Every detail line, for readable assertions.
	 *
	 * @return array<int, string>
	 */
	public function details(): array {
		return array_map(
			static fn ( RunLogEntry $entry ): string => (string) $entry->detail,
			$this->entries
		);
	}
}
