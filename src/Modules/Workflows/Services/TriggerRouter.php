<?php
/**
 * Turns domain events into runs.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Services;

use Hiveclerk\Core\Licence\Feature;
use Hiveclerk\Core\Licence\LicenceGate;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Workflow\SubjectType;
use Hiveclerk\Domain\Workflow\TriggerEvent;
use Hiveclerk\Domain\Workflow\Workflow;
use Hiveclerk\Domain\Workflow\WorkflowContext;
use Hiveclerk\Domain\Workflow\WorkflowRepositoryInterface;
use Hiveclerk\Domain\Workflow\WorkflowRun;
use Hiveclerk\Domain\Workflow\WorkflowRunRepositoryInterface;
use Hiveclerk\Modules\Workflows\Jobs\WorkflowTickJob;

/**
 * The only thing that opens a run (FR-WFL-01).
 *
 * ## It opens rows and nothing else
 *
 * The trigger for most workflows fires inside a visitor's request — a
 * lead being captured mid-conversation. Executing the first node there
 * would put a CRM's response time inside the chat reply. So this writes a
 * row, asks the queue to tick, and returns. The visitor waits for an
 * insert.
 *
 * ## Three guards, and each stops a different disaster
 *
 * Re-entry is refused by a unique index in the database rather than by a
 * read here, because two events in the same second from two requests
 * would both read "no open run". `runsOnce` is the second: a lead whose
 * stage changes four times in an afternoon fires the stage trigger four
 * times, and the default is that they go through the workflow once. The
 * third is {@see WorkflowEngine::isExecuting()} — a workflow action that
 * moves a stage fires the stage event, and without the guard a pair of
 * workflows can trigger each other until the site falls over.
 */
final class TriggerRouter {

	/**
	 * Construct.
	 *
	 * @param WorkflowRepositoryInterface    $workflows Workflow storage.
	 * @param WorkflowRunRepositoryInterface $runs      Run storage.
	 * @param LeadRepositoryInterface        $leads     Lead lookup, for sweeps.
	 * @param ContextBuilder                 $contexts  Context building.
	 * @param WorkflowEngine                 $engine    Engine, for the re-entry guard.
	 * @param QueueInterface                 $queue     Background work.
	 * @param LicenceGate                    $licence   Tier entitlements.
	 * @param ClockInterface                 $clock     Clock.
	 */
	public function __construct(
		private readonly WorkflowRepositoryInterface $workflows,
		private readonly WorkflowRunRepositoryInterface $runs,
		private readonly LeadRepositoryInterface $leads,
		private readonly ContextBuilder $contexts,
		private readonly WorkflowEngine $engine,
		private readonly QueueInterface $queue,
		private readonly LicenceGate $licence,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * A lead event happened.
	 *
	 * @param TriggerEvent $trigger What happened.
	 * @param Lead         $lead    The lead.
	 * @param int|null     $stageId Stage entered, under the stage trigger.
	 * @return int Runs opened.
	 */
	public function onLead( TriggerEvent $trigger, Lead $lead, ?int $stageId = null ): int {
		if ( null === $lead->id || ! $this->accepting() ) {
			return 0;
		}

		$opened = 0;

		foreach ( $this->workflows->liveFor( $trigger ) as $workflow ) {
			if ( ! $workflow->triggerMatches( $stageId ) ) {
				continue;
			}

			$context = $this->contexts->forLead( $lead )
				->with( array( 'trigger.stage_id' => $stageId ) );

			$opened += $this->open( $workflow, SubjectType::Lead, $lead->id, $context ) ? 1 : 0;
		}

		return $this->settle( $opened );
	}

	/**
	 * A conversation event happened.
	 *
	 * @param TriggerEvent $trigger      What happened.
	 * @param Conversation $conversation The conversation.
	 * @param string|null  $reason       Handoff reason, where there is one.
	 * @return int Runs opened.
	 */
	public function onConversation( TriggerEvent $trigger, Conversation $conversation, ?string $reason = null ): int {
		if ( null === $conversation->id || ! $this->accepting() ) {
			return 0;
		}

		$opened = 0;

		foreach ( $this->workflows->liveFor( $trigger ) as $workflow ) {
			$context = $this->contexts->forConversation( $conversation )
				->with( array( 'conversation.handoff_reason' => $reason ) );

			$opened += $this->open( $workflow, SubjectType::Conversation, $conversation->id, $context ) ? 1 : 0;
		}

		return $this->settle( $opened );
	}

	/**
	 * Open runs for every scheduled workflow whose interval has elapsed.
	 *
	 * Called from the tick, so a site with no scheduled workflows pays one
	 * indexed query every five minutes and nothing else.
	 *
	 * @return int Runs opened.
	 */
	public function sweepSchedules(): int {
		if ( ! $this->accepting() ) {
			return 0;
		}

		$due    = $this->workflows->dueSchedules( $this->clock->nowSql(), 5 );
		$opened = 0;

		foreach ( $due as $workflow ) {
			$opened += $this->sweep( $workflow );
		}

		return $this->settle( $opened );
	}

	/**
	 * One scheduled workflow's sweep.
	 *
	 * @param Workflow $workflow Workflow.
	 * @return int Runs opened.
	 */
	private function sweep( Workflow $workflow ): int {
		if ( null === $workflow->id ) {
			return 0;
		}

		$opened = 0;

		// Newest first and capped. A workflow pointed at a segment of
		// forty thousand leads should work through them over days rather
		// than opening forty thousand rows in one tick — which is a queue
		// nobody can stop and a mail run nobody authorised.
		$batch = $this->leads->batch( $workflow->segment(), Workflow::SCHEDULE_BATCH, 0 );

		foreach ( $batch as $lead ) {
			if ( null === $lead->id ) {
				continue;
			}

			$opened += $this->open(
				$workflow,
				SubjectType::Lead,
				$lead->id,
				$this->contexts->forLead( $lead )
			) ? 1 : 0;
		}

		// Written whether or not anything was opened. A sweep that matched
		// nobody must still move its clock forward, or every tick from now
		// on repeats a query that found nothing.
		$this->workflows->recordRuns(
			$workflow->id,
			$opened,
			$this->clock->now()->modify( '+' . $workflow->intervalMinutes() . ' minutes' )
		);

		return $opened;
	}

	/**
	 * Open one run, if the workflow's rules allow it.
	 *
	 * @param Workflow        $workflow  Workflow.
	 * @param SubjectType     $subject   Subject kind.
	 * @param int             $subjectId Subject.
	 * @param WorkflowContext $context   What the trigger saw.
	 * @return bool Whether a run was opened.
	 */
	private function open(
		Workflow $workflow,
		SubjectType $subject,
		int $subjectId,
		WorkflowContext $context
	): bool {
		if ( null === $workflow->id || null === $workflow->graph->entry() ) {
			// A live workflow whose graph has nothing after the trigger.
			// Refused at activation, so this is a row edited around it.
			return false;
		}

		if ( $workflow->runsOnce && $this->runs->hasRun( $workflow->id, $subjectId ) ) {
			return false;
		}

		$run = new WorkflowRun(
			id: null,
			workflowId: $workflow->id,
			subjectType: $subject,
			subjectId: $subjectId,
			currentNode: $workflow->graph->entry(),
			resumeAt: $this->clock->now(),
			context: $context->all(),
			startedAt: $this->clock->now(),
		);

		$saved = $this->runs->save( $run );

		if ( null === $saved->id ) {
			// The unique index refused a second open run for this subject.
			// The guard working, not an error.
			return false;
		}

		if ( ! $workflow->trigger->isScheduled() ) {
			// Scheduled sweeps write their own counters once, at the end.
			$this->workflows->recordRuns( $workflow->id, 1, $workflow->nextRunAt );
		}

		return true;
	}

	/**
	 * Whether new runs may be opened at all right now.
	 *
	 * @return bool
	 */
	private function accepting(): bool {
		// A lapsed licence stops new runs and leaves the open ones to
		// finish. Stopping mid-run would leave leads parked between a
		// stage change and the email that explains it, which is a worse
		// thing to do to a customer than one more week of automation.
		return ! $this->engine->isExecuting() && $this->licence->allows( Feature::Workflows );
	}

	/**
	 * Ask for a tick when anything was opened.
	 *
	 * @param int $opened Runs opened.
	 * @return int The same count, for the caller.
	 */
	private function settle( int $opened ): int {
		if ( $opened > 0 ) {
			$this->queue->enqueue( WorkflowTickJob::hook() );
		}

		return $opened;
	}
}
