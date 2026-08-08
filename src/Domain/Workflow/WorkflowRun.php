<?php
/**
 * One execution of a workflow against one subject.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Workflow;

use DateTimeImmutable;

/**
 * The state a run resumes from.
 *
 * Everything needed to continue is a column: which node is next, when it
 * may be entered, how many steps have been taken. Nothing is held in
 * memory between ticks, because there is no "between ticks" — the process
 * that started this run exited days ago, possibly on a different version
 * of the plugin.
 *
 * The context is a copy, not a reference. A run that read the lead's
 * score at the trigger and acts on it two days later is answering "what
 * was true when this started", and a run that re-read it would produce
 * decisions whose reason nobody can reconstruct from the log. The engine
 * refreshes the subject's live fields at each step for conditions that
 * should see them; the snapshot is what the trigger saw.
 */
final class WorkflowRun {

	/**
	 * Nodes a single run may enter before it is stopped.
	 *
	 * The graph validator already refuses cycles, so this only fires if a
	 * graph got past it — a row edited by hand, a bug here. A run that
	 * stops with "too many steps" in its log is a bug report; a run that
	 * never stops is an incident.
	 */
	public const MAX_STEPS = 200;

	/**
	 * Times a failing node is retried before the run is failed.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Construct.
	 *
	 * @param int|null               $id          Storage id, null before first save.
	 * @param int                    $workflowId  Which workflow.
	 * @param SubjectType            $subjectType What kind of record.
	 * @param int|null               $subjectId   Which record.
	 * @param RunStatus              $status      Where it has got to.
	 * @param string|null            $currentNode Node to enter next.
	 * @param DateTimeImmutable|null $resumeAt    When it may be entered, UTC.
	 * @param int                    $attempts    Failures on the current node.
	 * @param int                    $steps       Nodes entered so far.
	 * @param array<string, mixed>   $context     What the trigger saw.
	 * @param string|null            $error       Why it failed, when it did.
	 * @param DateTimeImmutable|null $startedAt   Run creation, UTC.
	 * @param DateTimeImmutable|null $updatedAt   Last write, UTC.
	 * @param DateTimeImmutable|null $finishedAt  Completion, UTC.
	 */
	public function __construct(
		public ?int $id,
		public int $workflowId,
		public SubjectType $subjectType = SubjectType::Lead,
		public ?int $subjectId = null,
		public RunStatus $status = RunStatus::Pending,
		public ?string $currentNode = null,
		public ?DateTimeImmutable $resumeAt = null,
		public int $attempts = 0,
		public int $steps = 0,
		public array $context = array(),
		public ?string $error = null,
		public ?DateTimeImmutable $startedAt = null,
		public ?DateTimeImmutable $updatedAt = null,
		public ?DateTimeImmutable $finishedAt = null,
	) {
	}

	/**
	 * Move to a node, to be entered immediately.
	 *
	 * @param string|null            $nodeId Next node, or null to finish.
	 * @param DateTimeImmutable|null $now    Current time, UTC.
	 * @return void
	 */
	public function moveTo( ?string $nodeId, ?DateTimeImmutable $now = null ): void {
		if ( null === $nodeId ) {
			$this->complete( $now );

			return;
		}

		$this->currentNode = $nodeId;
		$this->resumeAt    = $now;
		$this->status      = RunStatus::Pending;
		$this->attempts    = 0;
		++$this->steps;
	}

	/**
	 * Pause on a delay until a given time.
	 *
	 * @param string            $nodeId Node to enter when it resumes.
	 * @param DateTimeImmutable $until  When, UTC.
	 * @return void
	 */
	public function waitUntil( string $nodeId, DateTimeImmutable $until ): void {
		$this->currentNode = $nodeId;
		$this->resumeAt    = $until;
		$this->status      = RunStatus::Waiting;
		$this->attempts    = 0;
		++$this->steps;
	}

	/**
	 * Try the current node again later.
	 *
	 * @param DateTimeImmutable $at When, UTC.
	 * @return void
	 */
	public function retryAt( DateTimeImmutable $at ): void {
		++$this->attempts;
		$this->resumeAt = $at;
		$this->status   = RunStatus::Waiting;
	}

	/**
	 * Finish successfully.
	 *
	 * @param DateTimeImmutable|null $now Current time, UTC.
	 * @return void
	 */
	public function complete( ?DateTimeImmutable $now = null ): void {
		$this->status      = RunStatus::Completed;
		$this->currentNode = null;
		$this->resumeAt    = null;
		$this->finishedAt  = $now;
	}

	/**
	 * Stop with a reason.
	 *
	 * @param string                 $reason Why.
	 * @param DateTimeImmutable|null $now    Current time, UTC.
	 * @return void
	 */
	public function fail( string $reason, ?DateTimeImmutable $now = null ): void {
		$this->status     = RunStatus::Failed;
		$this->error      = $reason;
		$this->resumeAt   = null;
		$this->finishedAt = $now;
	}

	/**
	 * Stop because the workflow or its subject went away.
	 *
	 * @param string                 $reason Why.
	 * @param DateTimeImmutable|null $now    Current time, UTC.
	 * @return void
	 */
	public function cancel( string $reason, ?DateTimeImmutable $now = null ): void {
		$this->status     = RunStatus::Cancelled;
		$this->error      = $reason;
		$this->resumeAt   = null;
		$this->finishedAt = $now;
	}

	/**
	 * Whether this run has entered more nodes than any sane graph would.
	 *
	 * @return bool
	 */
	public function isRunaway(): bool {
		return $this->steps >= self::MAX_STEPS;
	}

	/**
	 * Whether the current node has failed too often to keep trying.
	 *
	 * @return bool
	 */
	public function isExhausted(): bool {
		return $this->attempts >= self::MAX_ATTEMPTS;
	}
}
