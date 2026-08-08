<?php
/**
 * Builds the data a run reasons about.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Services;

use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;
use Hiveclerk\Domain\Workflow\SubjectType;
use Hiveclerk\Domain\Workflow\WorkflowContext;
use Hiveclerk\Domain\Workflow\WorkflowRun;

/**
 * Turns a subject into the flat map conditions read.
 *
 * ## Rebuilt at every step, not carried from the trigger
 *
 * A run that waits two days and then asks "is the score still under 40"
 * has to be asking about today's score. Carrying the trigger's snapshot
 * forward would make every condition after a delay a question about the
 * past, and the most common workflow shape in this product is exactly
 * that: wait, then check whether anything changed.
 *
 * What the trigger saw is not lost — it stays on the run under `trigger.`
 * keys, so a graph can still compare then with now. It is just not what a
 * bare field name means.
 *
 * One lead lookup per step is the cost, against a primary key, inside a
 * job that already writes a log row for the same step.
 */
final class ContextBuilder {

	/**
	 * Construct.
	 *
	 * @param LeadRepositoryInterface         $leads         Lead lookup.
	 * @param LeadStageRepositoryInterface    $stages        Stage names.
	 * @param ConversationRepositoryInterface $conversations Conversation lookup.
	 * @param ClockInterface                  $clock         Clock.
	 */
	public function __construct(
		private readonly LeadRepositoryInterface $leads,
		private readonly LeadStageRepositoryInterface $stages,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * The context for a run as it stands right now.
	 *
	 * @param WorkflowRun $run Run.
	 * @return WorkflowContext|null Null when the subject has gone.
	 */
	public function forRun( WorkflowRun $run ): ?WorkflowContext {
		$stored = new WorkflowContext( $run->context );

		if ( null === $run->subjectId ) {
			return $stored;
		}

		return match ( $run->subjectType ) {
			SubjectType::Lead         => $this->forLeadId( $run->subjectId, $stored ),
			SubjectType::Conversation => $this->forConversationId( $run->subjectId, $stored ),
		};
	}

	/**
	 * The context for a lead.
	 *
	 * @param Lead                 $lead  Lead.
	 * @param WorkflowContext|null $onto  Values to keep alongside.
	 * @return WorkflowContext
	 */
	public function forLead( Lead $lead, ?WorkflowContext $onto = null ): WorkflowContext {
		$base = $onto ?? new WorkflowContext();

		return $base->with(
			array(
				'subject'                 => SubjectType::Lead->value,
				'lead.id'                 => $lead->id,
				'lead.uuid'               => $lead->uuid->value,
				'lead.name'               => $lead->displayName(),
				'lead.email'              => $lead->email,
				'lead.phone'              => $lead->phone,
				'lead.company'            => $lead->company,
				'lead.score'              => $lead->score,
				'lead.band'               => $lead->band->value,
				'lead.stage_id'           => $lead->stageId,
				'lead.stage'              => $this->stageName( $lead->stageId ),
				'lead.status'             => $lead->status->value,
				'lead.source'             => $lead->source,
				'lead.days_since_created' => $this->daysSince( $lead ),
				'lead.answers'            => $lead->customFields,
			)
		);
	}

	/**
	 * The context for a conversation.
	 *
	 * @param Conversation         $conversation Conversation.
	 * @param WorkflowContext|null $onto         Values to keep alongside.
	 * @return WorkflowContext
	 */
	public function forConversation( Conversation $conversation, ?WorkflowContext $onto = null ): WorkflowContext {
		$base = $onto ?? new WorkflowContext();

		$context = $base->with(
			array(
				'subject'               => SubjectType::Conversation->value,
				'conversation.id'       => $conversation->id,
				'conversation.uuid'     => $conversation->uuid->value,
				'conversation.agent_id' => $conversation->agentId,
			)
		);

		// A conversation that has already produced a lead carries it, so a
		// handoff workflow can still read a score. One extra lookup on the
		// path where it is the whole point, and none where it is not.
		if ( null !== $conversation->leadId ) {
			$lead = $this->leads->find( $conversation->leadId );

			if ( null !== $lead ) {
				$context = $this->forLead( $lead, $context )
					->with( array( 'subject' => SubjectType::Conversation->value ) );
			}
		}

		return $context;
	}

	/**
	 * The context for a lead id.
	 *
	 * @param int             $leadId Lead.
	 * @param WorkflowContext $onto   Values to keep alongside.
	 * @return WorkflowContext|null Null when the lead has been deleted.
	 */
	private function forLeadId( int $leadId, WorkflowContext $onto ): ?WorkflowContext {
		$lead = $this->leads->find( $leadId );

		return null === $lead ? null : $this->forLead( $lead, $onto );
	}

	/**
	 * The context for a conversation id.
	 *
	 * @param int             $conversationId Conversation.
	 * @param WorkflowContext $onto           Values to keep alongside.
	 * @return WorkflowContext|null Null when the conversation has been purged.
	 */
	private function forConversationId( int $conversationId, WorkflowContext $onto ): ?WorkflowContext {
		$conversation = $this->conversations->find( $conversationId );

		return null === $conversation ? null : $this->forConversation( $conversation, $onto );
	}

	/**
	 * A stage's name, for the log and for equality conditions.
	 *
	 * @param int|null $stageId Stage.
	 * @return string|null
	 */
	private function stageName( ?int $stageId ): ?string {
		if ( null === $stageId ) {
			return null;
		}

		return $this->stages->find( $stageId )?->name;
	}

	/**
	 * Whole days since the lead was captured.
	 *
	 * @param Lead $lead Lead.
	 * @return int
	 */
	private function daysSince( Lead $lead ): int {
		$created = $lead->createdAt ?? $lead->firstSeenAt;

		if ( null === $created ) {
			return 0;
		}

		$seconds = $this->clock->now()->getTimestamp() - $created->getTimestamp();

		return $seconds <= 0 ? 0 : intdiv( $seconds, 86400 );
	}
}
