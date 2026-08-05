<?php
/**
 * Score event storage without a database.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Support\Leads;

use Hiveclerk\Domain\Lead\ScoreEvent;
use Hiveclerk\Domain\Lead\ScoreEventRepositoryInterface;

/**
 * Score event storage without a database.
 *
 * @internal
 */
final class InMemoryScoreEvents implements ScoreEventRepositoryInterface {

	/**
	 * Every appended event, in order.
	 *
	 * @var array<int, ScoreEvent>
	 */
	public array $events = array();

	public function append( ScoreEvent $event ): ScoreEvent {
		$event->id      = count( $this->events ) + 1;
		$this->events[] = $event;

		return $event;
	}

	public function forLead( int $leadId, int $limit = 200 ): array {
		return array_values(
			array_filter(
				$this->events,
				static fn ( ScoreEvent $event ): bool => $event->leadId === $leadId
			)
		);
	}

	public function awardedRuleIds( int $leadId ): array {
		$ids = array();

		foreach ( $this->forLead( $leadId ) as $event ) {
			if ( null !== $event->ruleId ) {
				$ids[] = $event->ruleId;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	public function total( int $leadId ): int {
		$total = 0;

		foreach ( $this->forLead( $leadId ) as $event ) {
			$total += $event->points;
		}

		return $total;
	}

	public function reassign( int $from, int $to ): int {
		$moved = 0;

		foreach ( $this->events as $event ) {
			if ( $event->leadId === $from ) {
				$event->leadId = $to;
				++$moved;
			}
		}

		return $moved;
	}

	public function deleteForLead( int $leadId ): int {
		$before = count( $this->events );

		$this->events = array_values(
			array_filter(
				$this->events,
				static fn ( ScoreEvent $event ): bool => $event->leadId !== $leadId
			)
		);

		return $before - count( $this->events );
	}
}
