<?php
/**
 * Workflow entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

use DateTimeImmutable;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * An automation: one trigger, one graph, one set of rules about re-entry.
 *
 * ## `runsOnce` defaults to true, and that default is the safety rail
 *
 * A lead whose stage changes four times in an afternoon fires the stage
 * trigger four times. With re-entry allowed that is four runs, four
 * emails and one very annoyed recipient. Sequences learned this already —
 * a lead is enrolled once however often the trigger fires — and the
 * default here matches, so the destructive configuration is the one an
 * operator has to reach for deliberately.
 *
 * ## The schedule fields are on the workflow, not on the trigger config
 *
 * `nextRunAt` is written by the tick after every scheduled sweep, so it
 * is state, not configuration, and burying state inside a JSON column is
 * how a "run every 6 hours" workflow ends up running every request on the
 * one site whose JSON column failed to decode.
 */
final class Workflow {

	/**
	 * Shortest interval a scheduled workflow may use: one hour.
	 *
	 * The tick runs every five minutes and a scheduled sweep queries the
	 * lead table. Anything faster than hourly is a recurring table scan
	 * bought with the customer's database, for a product whose delays are
	 * measured in days.
	 */
	public const MIN_INTERVAL_MINUTES = 60;

	/**
	 * Leads a single scheduled sweep may enrol.
	 *
	 * A ceiling rather than a page: the sweep takes the newest matching
	 * leads and comes back next interval. A workflow pointed at forty
	 * thousand leads should be slow and visible, not a single tick that
	 * opens forty thousand runs and a queue nobody can stop.
	 */
	public const SCHEDULE_BATCH = 100;

	/**
	 * Construct.
	 *
	 * @param int|null               $id            Storage id, null before first save.
	 * @param Uuid                   $uuid          Public identifier.
	 * @param string                 $name          What the operator called it.
	 * @param WorkflowStatus         $status        Draft, active, paused or archived.
	 * @param TriggerEvent           $trigger       What opens a run.
	 * @param array<string, mixed>   $triggerConfig Stage to watch, segment filter, interval.
	 * @param WorkflowGraph          $graph         The nodes.
	 * @param bool                   $runsOnce      Whether a subject may run more than once.
	 * @param DateTimeImmutable|null $nextRunAt     Next scheduled sweep, UTC.
	 * @param int                    $runCount      Runs opened, ever.
	 * @param DateTimeImmutable|null $lastRunAt     Most recent run opened, UTC.
	 * @param DateTimeImmutable|null $createdAt     Row creation, UTC.
	 * @param DateTimeImmutable|null $updatedAt     Last write, UTC.
	 * @param DateTimeImmutable|null $deletedAt     Soft deletion, UTC.
	 */
	public function __construct(
		public ?int $id,
		public Uuid $uuid,
		public string $name,
		public WorkflowStatus $status = WorkflowStatus::Draft,
		public TriggerEvent $trigger = TriggerEvent::LeadCaptured,
		public array $triggerConfig = array(),
		public WorkflowGraph $graph = new WorkflowGraph(),
		public bool $runsOnce = true,
		public ?DateTimeImmutable $nextRunAt = null,
		public int $runCount = 0,
		public ?DateTimeImmutable $lastRunAt = null,
		public ?DateTimeImmutable $createdAt = null,
		public ?DateTimeImmutable $updatedAt = null,
		public ?DateTimeImmutable $deletedAt = null,
	) {
	}

	/**
	 * What kind of record this workflow's runs are about.
	 *
	 * @return SubjectType
	 */
	public function subject(): SubjectType {
		return $this->trigger->subject();
	}

	/**
	 * Whether a trigger may open a new run right now.
	 *
	 * @return bool
	 */
	public function isLive(): bool {
		return null === $this->deletedAt && $this->status->starts();
	}

	/**
	 * The stage this workflow watches, under the stage trigger.
	 *
	 * @return int|null
	 */
	public function watchedStageId(): ?int {
		$value = $this->triggerConfig['stage_id'] ?? null;

		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Minutes between scheduled sweeps.
	 *
	 * @return int
	 */
	public function intervalMinutes(): int {
		$value = $this->triggerConfig['interval'] ?? null;

		if ( ! is_numeric( $value ) ) {
			return 1440;
		}

		return max( self::MIN_INTERVAL_MINUTES, (int) $value );
	}

	/**
	 * The lead filter a scheduled sweep runs against.
	 *
	 * Only keys the lead repository already understands survive. A filter
	 * key it does not recognise is ignored there, which would silently
	 * widen the segment from "qualified leads with an address" to
	 * "everybody" — the difference between forty emails and forty thousand.
	 *
	 * @return array<string, mixed>
	 */
	public function segment(): array {
		$filters = $this->triggerConfig['segment'] ?? null;

		if ( ! is_array( $filters ) ) {
			return array();
		}

		$allowed = array( 'stage_id', 'status', 'band', 'source', 'min_score', 'has_email' );
		$clean   = array();

		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $filters ) ) {
				$clean[ $key ] = $filters[ $key ];
			}
		}

		return $clean;
	}

	/**
	 * Whether the trigger's own configuration matches this event.
	 *
	 * @param int|null $stageId Stage the subject just entered, where relevant.
	 * @return bool
	 */
	public function triggerMatches( ?int $stageId = null ): bool {
		if ( ! $this->trigger->needsStage() ) {
			return true;
		}

		$watched = $this->watchedStageId();

		// No stage chosen means any stage. An operator who has not picked
		// one has said "when it moves", and refusing to fire would make the
		// workflow look broken rather than unconfigured.
		return null === $watched || $watched === $stageId;
	}
}
