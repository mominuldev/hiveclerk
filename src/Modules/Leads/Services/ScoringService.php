<?php
/**
 * Scoring a lead.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Services;

use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Lead\Activity;
use Hiveclerk\Domain\Lead\ActivityRepositoryInterface;
use Hiveclerk\Domain\Lead\ActivityType;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStatus;
use Hiveclerk\Domain\Lead\ScoreBand;
use Hiveclerk\Domain\Lead\ScoreEvent;
use Hiveclerk\Domain\Lead\ScoreEventRepositoryInterface;
use Hiveclerk\Domain\Lead\ScoreSource;
use Hiveclerk\Domain\Lead\Scoring\ScoringRule;

/**
 * The only thing in the product allowed to change a lead's score.
 *
 * Every change is two writes in a fixed order: an immutable event
 * carrying its own explanation and running total, then the materialised
 * column that the pipeline sorts by. Written the other way round, a
 * failure between them leaves a lead whose number nobody can account for
 * — which is precisely the state FR-LED-04 exists to make impossible.
 *
 * Nothing here calls a provider. The AI adjustment arrives as a finished
 * verdict from {@see AiScorer}, off the request path, and is recorded by
 * the same method a rule uses.
 */
final class ScoringService {

	/**
	 * Construct.
	 *
	 * @param LeadRepositoryInterface       $leads      Lead storage.
	 * @param ScoreEventRepositoryInterface $events     Append-only score log.
	 * @param ActivityRepositoryInterface   $activities Timeline.
	 * @param ScoringPolicy                 $policy     Rules and thresholds.
	 * @param SignalCollector               $signals    Fact gathering.
	 * @param LeadNotifier                  $notifier   Threshold alerts.
	 * @param ClockInterface                $clock      Time source.
	 */
	public function __construct(
		private readonly LeadRepositoryInterface $leads,
		private readonly ScoreEventRepositoryInterface $events,
		private readonly ActivityRepositoryInterface $activities,
		private readonly ScoringPolicy $policy,
		private readonly SignalCollector $signals,
		private readonly LeadNotifier $notifier,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Run the rule set against a lead and record what fired (FR-LED-03).
	 *
	 * Cheap by construction: one query for what has already been paid,
	 * an in-memory pass over the rules, and a write only when something
	 * new fires. On the common turn — a visitor saying "thanks" — this
	 * costs one SELECT and nothing else, which is why it runs inline
	 * rather than as a job.
	 *
	 * @param Lead                    $lead         The lead.
	 * @param Conversation|null       $conversation Conversation that triggered it.
	 * @param array<int, string>|null $said         Visitor messages the caller has already read.
	 * @return int Points awarded this pass.
	 */
	public function applyRules( Lead $lead, ?Conversation $conversation = null, ?array $said = null ): int {
		if ( null === $lead->id ) {
			return 0;
		}

		$rules = $this->policy->rules();

		if ( $rules->isEmpty() ) {
			return 0;
		}

		$matched = $rules->evaluate(
			$this->signals->collect( $lead, $conversation, $said ),
			$this->events->awardedRuleIds( $lead->id )
		);

		if ( array() === $matched ) {
			return 0;
		}

		$awarded = 0;

		foreach ( $matched as $rule ) {
			$awarded += $this->record(
				$lead,
				new ScoreEvent(
					id: null,
					leadId: $lead->id,
					conversationId: $conversation?->id,
					ruleId: $rule->id,
					ruleLabel: $rule->label,
					source: ScoreSource::Rule,
					points: $this->weight( $rule, $lead ),
					createdAt: $this->clock->now(),
				)
			);
		}

		return $awarded;
	}

	/**
	 * Record a model's adjustment (FR-LED-04).
	 *
	 * Refused without a rationale. An unexplained number from a model is
	 * the thing a sales team stops trusting the whole score over, and
	 * storing one because the model happened not to write a sentence
	 * would leave a line in the breakdown that says only "AI: +12".
	 *
	 * @param Lead              $lead         The lead.
	 * @param int               $points       Signed adjustment.
	 * @param string            $rationale    Why, in the model's words.
	 * @param string            $label        Breakdown label.
	 * @param Conversation|null $conversation Conversation that triggered it.
	 * @return bool Whether it was recorded.
	 */
	public function applyAiAdjustment(
		Lead $lead,
		int $points,
		string $rationale,
		string $label,
		?Conversation $conversation = null
	): bool {
		if ( null === $lead->id ) {
			return false;
		}

		$trimmed = trim( $label );

		$event = new ScoreEvent(
			id: null,
			leadId: $lead->id,
			conversationId: $conversation?->id,
			ruleId: null,
			ruleLabel: '' === $trimmed ? 'Model assessment' : $trimmed,
			source: ScoreSource::Ai,
			points: $points,
			rationale: trim( $rationale ),
			createdAt: $this->clock->now(),
		);

		if ( ! $event->isValid() ) {
			return false;
		}

		$this->record( $lead, $event );

		return true;
	}

	/**
	 * Record an operator's manual adjustment.
	 *
	 * @param Lead        $lead   The lead.
	 * @param int         $points Signed adjustment.
	 * @param string|null $reason What they typed, when they typed anything.
	 * @param int|null    $userId Who did it.
	 * @return bool Whether it was recorded.
	 */
	public function applyManualAdjustment(
		Lead $lead,
		int $points,
		?string $reason = null,
		?int $userId = null
	): bool {
		if ( null === $lead->id || 0 === $points ) {
			return false;
		}

		$this->record(
			$lead,
			new ScoreEvent(
				id: null,
				leadId: $lead->id,
				ruleId: null,
				ruleLabel: null === $reason || '' === trim( $reason )
					? 'Adjusted by hand'
					: trim( $reason ),
				source: ScoreSource::Manual,
				points: $points,
				// Deliberately null. What the operator typed is already the
				// label; repeating it underneath as a rationale prints the
				// same sentence twice in the one place on this screen where
				// every line is supposed to say something new.
				rationale: null,
				createdAt: $this->clock->now(),
			),
			$userId
		);

		return true;
	}

	/**
	 * The breakdown behind a lead's score (FR-LED-04).
	 *
	 * @param Lead $lead The lead.
	 * @return array<string, mixed>
	 */
	public function breakdown( Lead $lead ): array {
		$events = null === $lead->id ? array() : $this->events->forLead( $lead->id );

		return array(
			'score'     => $lead->score,
			'band'      => $lead->band->value,
			'ceiling'   => $this->policy->ceiling(),
			'breakdown' => array_map(
				static fn ( ScoreEvent $event ): array => $event->toBreakdownLine(),
				$events
			),
		);
	}

	/**
	 * Rebuild the materialised total from the event log.
	 *
	 * The two are written together and can still drift — a crash between
	 * the two writes, a row restored from a partial backup. This is what
	 * makes that recoverable rather than permanent, and it is the reason
	 * `score_after` is stamped on every event rather than derived.
	 *
	 * @param Lead $lead The lead.
	 * @return int The repaired total.
	 */
	public function recalculate( Lead $lead ): int {
		if ( null === $lead->id ) {
			return $lead->score;
		}

		$total = $this->events->total( $lead->id );
		$band  = $this->policy->band( $total );

		if ( $total === $lead->score && $band === $lead->band ) {
			return $total;
		}

		$this->leads->updateScore( $lead->id, $total, $band );

		$lead->score = $total;
		$lead->band  = $band;

		return $total;
	}

	/**
	 * Write one event and move the materialised total with it.
	 *
	 * @param Lead          $lead   The lead.
	 * @param ScoreEvent    $event  The event, without its running total.
	 * @param int|null      $userId Operator, for a manual change.
	 * @return int Points awarded.
	 */
	private function record( Lead $lead, ScoreEvent $event, ?int $userId = null ): int {
		$before = $lead->score;
		$after  = $before + $event->points;

		// Read before anything is written. Whether this award crossed the
		// threshold is a fact about the band the lead had a moment ago,
		// and computing it after the write would make it depend on the
		// repository leaving the entity alone — which is true of the one
		// we ship and is not a property worth relying on.
		$was = $lead->band;

		// Stamped on the row rather than computed on read. A total
		// recalculated later would change retrospectively the first time
		// somebody edits a rule's weight, and the breakdown would stop
		// adding up to the history it claims to describe.
		$event->scoreAfter = $after;

		$this->events->append( $event );

		$band = $this->policy->band( $after );

		$this->leads->updateScore( (int) $lead->id, $after, $band );

		$crossed     = $band->isQualified() && ! $was->isQualified();
		$lead->score = $after;
		$lead->band  = $band;

		$this->activities->record(
			new Activity(
				id: null,
				type: ActivityType::ScoreChanged,
				title: sprintf(
					/* translators: 1: previous score, 2: new score, 3: what awarded the points. */
					__( 'Score %1$d → %2$d · %3$s', 'hiveclerk' ),
					$before,
					$after,
					$event->ruleLabel ?? $event->source->label()
				),
				leadId: $lead->id,
				subjectType: 'score_event',
				subjectId: $event->id,
				wpUserId: $userId,
				body: $event->rationale,
				metadata: array(
					'points' => $event->points,
					'source' => $event->source->value,
					'band'   => $band->value,
				),
				createdAt: $event->createdAt,
			)
		);

		if ( $crossed ) {
			$this->onQualified( $lead );
		}

		return $event->points;
	}

	/**
	 * A lead has crossed into the qualified band.
	 *
	 * The status follows the band, but only from a state that has not
	 * been overruled by a person. An operator who marked a lead
	 * unqualified has made a judgement the rules do not get to reverse
	 * because the visitor came back and read the pricing page again.
	 *
	 * @param Lead $lead The lead.
	 * @return void
	 */
	private function onQualified( Lead $lead ): void {
		if ( LeadStatus::New === $lead->status || LeadStatus::Contacted === $lead->status ) {
			$lead->status = LeadStatus::Qualified;

			$this->leads->save( $lead );
		}

		/**
		 * Fires when a lead's score crosses the qualified threshold.
		 *
		 * @param Lead $lead The lead.
		 */
		do_action( 'hiveclerk/lead/qualified', $lead );

		$this->notifier->onScoreChanged( $lead );
	}

	/**
	 * A rule's points, after the extension filter.
	 *
	 * The filter is on the individual award rather than on the total
	 * because the total is an event log's sum — a filter that returned a
	 * different number would make the breakdown disagree with the score
	 * above it, in the one screen built to prove they agree.
	 *
	 * @param ScoringRule $rule The rule that fired.
	 * @param Lead        $lead The lead.
	 * @return int
	 */
	private function weight( ScoringRule $rule, Lead $lead ): int {
		/**
		 * Filter the points a rule awards.
		 *
		 * @param int         $points Configured points.
		 * @param ScoringRule $rule   The rule that fired.
		 * @param Lead        $lead   The lead being scored.
		 */
		$filtered = apply_filters( 'hiveclerk/lead/score', $rule->points, $rule, $lead );

		if ( ! is_numeric( $filtered ) ) {
			return $rule->points;
		}

		return max( -ScoringRule::MAX_POINTS, min( ScoringRule::MAX_POINTS, (int) $filtered ) );
	}

	/**
	 * The band a score falls into under this site's boundaries.
	 *
	 * @param int $score Score.
	 * @return ScoreBand
	 */
	public function bandFor( int $score ): ScoreBand {
		return $this->policy->band( $score );
	}
}
