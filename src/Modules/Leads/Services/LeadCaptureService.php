<?php
/**
 * Turning a conversation into a lead.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Services;

use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\Message;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Conversation\MessageRole;
use Hiveclerk\Domain\Lead\ActivityType;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadCapture;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;
use Hiveclerk\Domain\Lead\VisitorRepositoryInterface;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Leads\Jobs\ScoreLeadJob;
use Hiveclerk\Modules\Leads\Support\AnswerMatcher;
use Hiveclerk\Modules\Leads\Support\ContactExtractor;
use Hiveclerk\Modules\Leads\Support\ExtractedContact;

/**
 * Reads a conversation, and creates or updates the person behind it
 * (FR-LED-01, FR-LED-02, FR-LED-08).
 *
 * ## Why this runs inline and the model does not
 *
 * Extraction is patterns and a bag of words: bounded work over a
 * transcript that is already capped, costing one query and no network.
 * The rule pass on top of it is an in-memory walk over at most sixty
 * rules. Together they are noise next to the provider call that just
 * happened, so an operator sees the lead appear while the visitor is
 * still typing.
 *
 * The model's opinion is the opposite shape — a second billable
 * completion — so it is a job, scheduled once per conversation and
 * delayed so that what it reads is a conversation rather than a
 * greeting. See {@see ScoreLeadJob}.
 *
 * ## Nothing here throws into the reply
 *
 * This is called from an action that fires inside the chat path. A
 * visitor's answer must not fail because a lead could not be written, so
 * the entry points return quietly rather than raising.
 */
final class LeadCaptureService {

	/**
	 * Messages read back to find the question a reply answered.
	 *
	 * Three: the clerk's question, the visitor's answer, and the reply
	 * that has just been stored on top of them.
	 */
	private const LOOKBACK = 3;

	/**
	 * Seconds before the model is asked what it thinks of a lead.
	 *
	 * Long enough for the conversation to become one. Asked at the moment
	 * of capture, the model would be reading two lines — a greeting and
	 * an email address — and writing a rationale about them, which is
	 * both a worse assessment and one an operator would read as the
	 * product not paying attention.
	 */
	private const AI_DELAY = 180;

	/**
	 * Construct.
	 *
	 * @param LeadRepositoryInterface         $leads         Lead storage.
	 * @param LeadStageRepositoryInterface    $stages        Stage storage.
	 * @param MessageRepositoryInterface      $messages      Message storage.
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 * @param VisitorRepositoryInterface      $visitors      Visitor storage.
	 * @param VisitorService                  $stitcher      Visitor stitching.
	 * @param ScoringService                  $scoring       Scoring.
	 * @param LeadService                     $leadService   Timeline writing.
	 * @param ContactExtractor                $contacts      Detail extraction.
	 * @param AnswerMatcher                   $answers       Question pairing.
	 * @param QueueInterface                  $queue         Background queue.
	 * @param ClockInterface                  $clock         Clock.
	 */
	public function __construct(
		private readonly LeadRepositoryInterface $leads,
		private readonly LeadStageRepositoryInterface $stages,
		private readonly MessageRepositoryInterface $messages,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly VisitorRepositoryInterface $visitors,
		private readonly VisitorService $stitcher,
		private readonly ScoringService $scoring,
		private readonly LeadService $leadService,
		private readonly ContactExtractor $contacts,
		private readonly AnswerMatcher $answers,
		private readonly QueueInterface $queue,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * A clerk has just replied. See what the visitor gave away.
	 *
	 * @param Conversation $conversation The conversation.
	 * @param Agent        $agent        The clerk.
	 * @return Lead|null The lead, when there is one.
	 */
	public function onReply( Conversation $conversation, Agent $agent ): ?Lead {
		$capture = LeadCapture::fromArray( $agent->leadConfig );

		if ( ! $capture->enabled || null === $conversation->id ) {
			return null;
		}

		$transcript = $this->messages->transcript( $conversation->id );
		$said       = $this->visitorWords( $transcript );

		$found  = $capture->scanReplies ? $this->contacts->fromMessages( $said ) : new ExtractedContact();
		$answer = $this->answeredQuestion( $transcript, $capture );

		$lead = $this->resolve( $conversation, $agent, $found );

		if ( null === $lead ) {
			return null;
		}

		$changed = $this->fill( $lead, $found );

		if ( null !== $answer && ! array_key_exists( $answer[0], $lead->customFields ) ) {
			$lead->customFields[ $answer[0] ] = $answer[1];

			$changed = true;
		}

		$lead->lastActiveAt = $this->clock->now();

		$this->leads->save( $lead );

		if ( $changed ) {
			$this->leadService->note(
				$lead,
				ActivityType::MessageSent,
				__( 'Details picked up from the conversation', 'hiveclerk' ),
				null,
				null,
				array( 'conversation' => $conversation->uuid->value )
			);
		}

		$this->scoring->applyRules( $lead, $conversation, $said );

		$this->scheduleAiScoring( $lead, $conversation );

		return $lead;
	}

	/**
	 * A visitor filled in the in-chat capture form.
	 *
	 * The direct path, and the one that never guesses. Everything here
	 * was typed into a labelled field, so it overwrites what pattern
	 * matching inferred rather than filling gaps around it.
	 *
	 * @param Conversation         $conversation The conversation.
	 * @param Agent                $agent        The clerk.
	 * @param array<string, mixed> $fields       Cleaned form fields.
	 * @return Lead|null
	 */
	public function captureFromForm( Conversation $conversation, Agent $agent, array $fields ): ?Lead {
		$email = isset( $fields['email'] ) ? (string) $fields['email'] : '';
		$hash  = '' === $email ? null : Lead::hashEmail( $email );

		$found = new ExtractedContact(
			email: null === $hash ? null : Lead::normaliseEmail( $email ),
			phone: $this->trimmed( $fields['phone'] ?? null, 50 ),
			firstName: $this->trimmed( $fields['first_name'] ?? null, 100 ),
			lastName: $this->trimmed( $fields['last_name'] ?? null, 100 ),
			company: $this->trimmed( $fields['company'] ?? null, 191 ),
		);

		if ( $found->isEmpty() ) {
			return null;
		}

		$lead = $this->resolve( $conversation, $agent, $found );

		if ( null === $lead ) {
			return null;
		}

		$this->fill( $lead, $found );

		// A form field beats an inference. The visitor typed this into a
		// box that said what it was for.
		foreach ( $found->fields() as $property => $value ) {
			$lead->{$property} = $value;
		}

		if ( isset( $fields['answers'] ) && is_array( $fields['answers'] ) ) {
			foreach ( $fields['answers'] as $key => $value ) {
				$clean = $this->trimmed( $value, 500 );

				if ( null !== $clean ) {
					$lead->customFields[ (string) $key ] = $clean;
				}
			}
		}

		if ( isset( $fields['consent'] ) && $fields['consent'] ) {
			$lead->consent = array(
				'marketing' => true,
				'timestamp' => $this->clock->now()->format( 'Y-m-d H:i:s' ),
				'text'      => $this->trimmed( $fields['consent_text'] ?? null, 500 ),
			);
		}

		$lead->lastActiveAt = $this->clock->now();

		$this->leads->save( $lead );

		$this->scoring->applyRules( $lead, $conversation );

		$this->scheduleAiScoring( $lead, $conversation );

		return $lead;
	}

	/**
	 * The lead this conversation belongs to, creating one if it should.
	 *
	 * A lead is created only once there is a way to reach the person. A
	 * row holding a first name and nothing else is not a lead, it is a
	 * card in the pipeline nobody can act on — and it would be created
	 * for every visitor who typed "I'm interested".
	 *
	 * @param Conversation     $conversation The conversation.
	 * @param Agent            $agent        The clerk.
	 * @param ExtractedContact $found        What was extracted.
	 * @return Lead|null
	 */
	private function resolve( Conversation $conversation, Agent $agent, ExtractedContact $found ): ?Lead {
		if ( null !== $conversation->leadId ) {
			return $this->leads->find( $conversation->leadId );
		}

		if ( null === $found->email && null === $found->phone ) {
			return null;
		}

		$hash     = null === $found->email ? null : Lead::hashEmail( $found->email );
		$existing = null === $hash ? null : $this->leads->findByEmailHash( $hash );

		if ( null !== $existing ) {
			// Deduplication (FR-LED-08): the same person coming back is the
			// same lead, and their second conversation joins the first
			// rather than starting a parallel record of them.
			$this->attach( $conversation, $existing );

			return $existing;
		}

		$lead = $this->leads->save(
			new Lead(
				id: null,
				uuid: Uuid::generate(),
				email: $found->email,
				emailHash: $hash,
				stageId: $this->defaultStageId(),
				source: $agent->slug,
				firstSeenAt: $this->clock->now(),
				lastActiveAt: $this->clock->now(),
			)
		);

		$this->attach( $conversation, $lead );

		$this->leadService->note(
			$lead,
			ActivityType::LeadCaptured,
			sprintf(
				/* translators: %s: name of the clerk that captured the lead. */
				__( 'Lead captured by %s', 'hiveclerk' ),
				$agent->name
			),
			null,
			null,
			array( 'conversation' => $conversation->uuid->value )
		);

		/**
		 * Fires the first time a conversation resolves to a person.
		 *
		 * @param Lead         $lead         The lead.
		 * @param Conversation $conversation The conversation that produced it.
		 */
		do_action( 'hiveclerk/lead/captured', $lead, $conversation );

		return $lead;
	}

	/**
	 * Point a conversation, and the visitor behind it, at a lead.
	 *
	 * @param Conversation $conversation The conversation.
	 * @param Lead         $lead         The lead.
	 * @return void
	 */
	private function attach( Conversation $conversation, Lead $lead ): void {
		if ( null === $conversation->id || null === $lead->id ) {
			return;
		}

		$this->conversations->attachLead( $conversation->id, $lead->id );

		// Mirrored onto the in-memory conversation so the rest of this
		// request — including the reply still being assembled — sees the
		// lead it just acquired.
		$conversation->leadId = $lead->id;

		if ( null === $conversation->visitorId ) {
			return;
		}

		$visitor = $this->visitors->find( $conversation->visitorId );

		if ( null !== $visitor ) {
			$this->stitcher->stitch( $visitor, $lead );
		}
	}

	/**
	 * Fill blank fields from what was extracted.
	 *
	 * Blank ones only. Capture runs on every reply, so the same address
	 * arrives many times over one conversation, and a later mention must
	 * not overwrite what an operator has since corrected by hand.
	 *
	 * @param Lead             $lead  The lead.
	 * @param ExtractedContact $found What was extracted.
	 * @return bool Whether anything changed.
	 */
	private function fill( Lead $lead, ExtractedContact $found ): bool {
		$changed = false;

		foreach ( $found->fields() as $property => $value ) {
			$changed = $lead->fillIfEmpty( $property, $value ) || $changed;
		}

		if ( null !== $found->email && null === $lead->emailHash ) {
			$hash = Lead::hashEmail( $found->email );

			// Only when nothing else has claimed it. A visitor who types a
			// colleague's address into a conversation belonging to somebody
			// already identified must not silently rewrite whose lead it is.
			if ( null !== $hash && null === $this->leads->findByEmailHash( $hash ) ) {
				$lead->email     = $found->email;
				$lead->emailHash = $hash;
				$changed         = true;
			}
		}

		return $changed;
	}

	/**
	 * The qualification question the visitor's last message answered.
	 *
	 * @param array<int, Message> $transcript Whole transcript, oldest first.
	 * @param LeadCapture         $capture    Clerk capture settings.
	 * @return array{0: string, 1: string}|null Key and answer.
	 */
	private function answeredQuestion( array $transcript, LeadCapture $capture ): ?array {
		if ( ! $capture->hasQuestions() ) {
			return null;
		}

		$tail = array_slice( $transcript, -self::LOOKBACK );

		$asked  = null;
		$answer = null;

		foreach ( $tail as $message ) {
			if ( MessageRole::Assistant === $message->role && null === $answer ) {
				$asked = $message->content;

				continue;
			}

			if ( MessageRole::Visitor === $message->role && null !== $asked ) {
				$answer = $message->content;
			}
		}

		if ( null === $asked || null === $answer || ! $this->answers->isAnswer( $answer ) ) {
			return null;
		}

		$question = $this->answers->questionAsked( $asked, $capture->questions );

		if ( null === $question ) {
			return null;
		}

		return array( $question->key, mb_substr( trim( $answer ), 0, 500 ) );
	}

	/**
	 * The visitor's own words, oldest first.
	 *
	 * @param array<int, Message> $transcript Whole transcript.
	 * @return array<int, string>
	 */
	private function visitorWords( array $transcript ): array {
		$said = array();

		foreach ( $transcript as $message ) {
			if ( MessageRole::Visitor === $message->role ) {
				$said[] = $message->content;
			}
		}

		return $said;
	}

	/**
	 * Ask the model what it makes of this lead, once, later.
	 *
	 * Keyed on the conversation rather than the lead: a returning
	 * customer's second conversation deserves its own assessment, and one
	 * pending job per conversation is what stops a talkative visitor from
	 * queueing a completion per message.
	 *
	 * @param Lead         $lead         The lead.
	 * @param Conversation $conversation The conversation.
	 * @return void
	 */
	private function scheduleAiScoring( Lead $lead, Conversation $conversation ): void {
		if ( null === $lead->id || null === $conversation->id ) {
			return;
		}

		$args = array(
			'lead'         => $lead->id,
			'conversation' => $conversation->id,
		);

		if ( $this->queue->isPending( ScoreLeadJob::hook(), $args ) ) {
			return;
		}

		$this->queue->scheduleAt(
			$this->clock->now()->getTimestamp() + self::AI_DELAY,
			ScoreLeadJob::hook(),
			$args
		);
	}

	/**
	 * The leftmost non-terminal stage.
	 *
	 * @return int|null
	 */
	private function defaultStageId(): ?int {
		foreach ( $this->stages->all() as $stage ) {
			if ( ! $stage->isTerminal() ) {
				return $stage->id;
			}
		}

		return null;
	}

	/**
	 * A trimmed, bounded string, or null.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Maximum length.
	 * @return string|null
	 */
	private function trimmed( mixed $value, int $limit ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed = trim( $value );

		return '' === $trimmed ? null : mb_substr( $trimmed, 0, $limit );
	}
}
