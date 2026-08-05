<?php
/**
 * The model's assessment of a lead, off the request path.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Jobs;

use Hiveclerk\Core\Queue\AbstractJob;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Lead\ActivityType;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Modules\Leads\Services\AiScorer;
use Hiveclerk\Modules\Leads\Services\ScoringService;

/**
 * Runs the AI score adjustment once per conversation (FR-LED-04).
 *
 * A job rather than part of the reply for the obvious reason — it is a
 * second billable completion — and for a less obvious one: it needs the
 * conversation to have finished being interesting. Scheduled three
 * minutes after capture, what it reads is a conversation. Run at the
 * moment of capture, it would be reading a greeting and an address.
 *
 * There is no batch and no re-enqueue. This job is one completion for one
 * lead, and a site with a thousand of them queued is a thousand jobs the
 * queue drains at its own pace rather than one job that has to decide how
 * many it can afford to run before the host's execution limit.
 */
final class ScoreLeadJob extends AbstractJob {

	/**
	 * Construct.
	 *
	 * @param AiScorer                        $scorer        Model assessment.
	 * @param ScoringService                  $scoring       Score writing.
	 * @param LeadRepositoryInterface         $leads         Lead storage.
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 * @param AgentRepositoryInterface        $agents        Clerk storage.
	 */
	public function __construct(
		private readonly AiScorer $scorer,
		private readonly ScoringService $scoring,
		private readonly LeadRepositoryInterface $leads,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly AgentRepositoryInterface $agents
	) {
	}

	/**
	 * Hook name.
	 *
	 * @return string
	 */
	public static function hook(): string {
		return 'hiveclerk/job/score_lead';
	}

	/**
	 * Assess one lead.
	 *
	 * Every missing piece returns rather than throwing. The arguments
	 * were serialised minutes ago and the lead may since have been
	 * merged away or deleted, which is a normal outcome and not a
	 * failure worth retrying.
	 *
	 * @param array<string, mixed> $args lead, conversation.
	 * @return void
	 */
	public function handle( array $args ): void {
		$lead         = $this->leads->find( self::intArg( $args, 'lead' ) );
		$conversation = $this->conversations->find( self::intArg( $args, 'conversation' ) );

		if ( null === $lead || null === $conversation ) {
			return;
		}

		$agent = $this->agents->find( $conversation->agentId );

		if ( null === $agent ) {
			return;
		}

		// A clerk past its monthly cap does not get to spend more of it on
		// an opinion the operator did not ask for. The rule-based score is
		// already on the lead and is the part that was promised.
		if ( $agent->isBudgetBlocked() ) {
			return;
		}

		$verdict = $this->scorer->assess( $lead, $conversation, $agent );

		if ( null === $verdict ) {
			return;
		}

		$this->scoring->applyAiAdjustment(
			$lead,
			$verdict['points'],
			$verdict['rationale'],
			$verdict['label'],
			$conversation
		);
	}

	/**
	 * The timeline type this job writes through the scoring service.
	 *
	 * Named here so a reader of the job can find where its output lands
	 * without following three constructors.
	 *
	 * @return ActivityType
	 */
	public static function writes(): ActivityType {
		return ActivityType::ScoreChanged;
	}
}
