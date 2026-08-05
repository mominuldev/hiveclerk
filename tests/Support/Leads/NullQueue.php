<?php
/**
 * A queue that records rather than schedules.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Leads;

use Hiveclerk\Core\Queue\QueueInterface;

/**
 * A queue that records rather than schedules.
 *
 * @internal
 */
final class NullQueue implements QueueInterface {

	/**
	 * Everything that was scheduled, in order.
	 *
	 * @var array<int, array{hook: string, args: array<string, mixed>, at: int|null}>
	 */
	public array $scheduled = array();

	public function enqueue( string $hook, array $args = array() ): bool {
		$this->scheduled[] = array(
			'hook' => $hook,
			'args' => $args,
			'at'   => null,
		);

		return true;
	}

	public function scheduleAt( int $timestamp, string $hook, array $args = array() ): bool {
		$this->scheduled[] = array(
			'hook' => $hook,
			'args' => $args,
			'at'   => $timestamp,
		);

		return true;
	}

	public function scheduleRecurring( int $interval, string $hook, array $args = array() ): bool {
		return $this->enqueue( $hook, $args );
	}

	public function cancel( string $hook, array $args = array() ): void {
		$this->scheduled = array_values(
			array_filter(
				$this->scheduled,
				static fn ( array $entry ): bool => $entry['hook'] !== $hook
			)
		);
	}

	public function isPending( string $hook, array $args = array() ): bool {
		foreach ( $this->scheduled as $entry ) {
			if ( $entry['hook'] === $hook && $entry['args'] === $args ) {
				return true;
			}
		}

		return false;
	}

	public function depth(): int {
		return count( $this->scheduled );
	}

	public function driver(): string {
		return 'test';
	}
}
