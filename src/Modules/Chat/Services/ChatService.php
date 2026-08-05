<?php
/**
 * Chat orchestration.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Services;

use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Ai\AiServiceInterface;
use Hiveclerk\Ai\ProviderException;
use Hiveclerk\Ai\StreamEvent;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\Citation;
use Hiveclerk\Domain\Conversation\CitationRepositoryInterface;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Conversation\Message;
use Hiveclerk\Domain\Conversation\MessageRepositoryInterface;
use Hiveclerk\Domain\Conversation\MessageRole;
use Hiveclerk\Domain\Knowledge\RetrievalOptions;
use Hiveclerk\Domain\Knowledge\RetrievalResult;
use Hiveclerk\Domain\Knowledge\RetrievedChunk;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Domain\Usage\UsageKind;
use Hiveclerk\Modules\Chat\Support\BuiltPrompt;
use Hiveclerk\Modules\Chat\Support\ChatOutcome;
use Hiveclerk\Modules\Chat\Support\ChatSink;
use Hiveclerk\Domain\Knowledge\RetrievalServiceInterface;

/**
 * One visitor message in, one stored and delivered reply out.
 *
 * The order of operations here is the product's cost model, not just its
 * control flow. Everything that can refuse the exchange runs before
 * anything that spends money: guardrails, then the budget, then the
 * conversation cap, and only then retrieval — which bills an embedding —
 * and completion, which bills the rest. A check placed after a provider
 * call is not a check, it is a report.
 *
 * The reply is written to a ChatSink rather than returned, so the same
 * path serves streaming and polling. See {@see ChatSink} for why that
 * matters more than it looks.
 *
 * @see docs/06-system-architecture.md §9
 */
final class ChatService {

	/**
	 * Citations attached to one reply.
	 *
	 * The widget shows a short list under the answer, and a reader checks
	 * one source or none. Ten citations is not more transparency, it is a
	 * wall that gets scrolled past.
	 */
	private const MAX_CITATIONS = 3;

	/**
	 * Construct.
	 *
	 * @param AiServiceInterface              $ai            Model access.
	 * @param RetrievalServiceInterface       $retrieval     Knowledge search.
	 * @param PromptBuilder                   $prompts       Prompt assembly.
	 * @param GuardrailService                $guardrails    Input and output checks.
	 * @param AgentRepositoryInterface        $agents        Clerk storage.
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 * @param MessageRepositoryInterface      $messages      Message storage.
	 * @param CitationRepositoryInterface     $citations     Citation storage.
	 */
	public function __construct(
		private readonly AiServiceInterface $ai,
		private readonly RetrievalServiceInterface $retrieval,
		private readonly PromptBuilder $prompts,
		private readonly GuardrailService $guardrails,
		private readonly AgentRepositoryInterface $agents,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly MessageRepositoryInterface $messages,
		private readonly CitationRepositoryInterface $citations
	) {
	}

	/**
	 * Answer one message.
	 *
	 * @param Agent                $agent        The clerk.
	 * @param Conversation         $conversation The conversation.
	 * @param string               $message      Visitor input, sanitised at the boundary.
	 * @param ChatSink             $sink         Where the reply is written.
	 * @param array<string, mixed> $context      Page url, page title, locale.
	 * @return ChatOutcome
	 */
	public function reply(
		Agent $agent,
		Conversation $conversation,
		string $message,
		ChatSink $sink,
		array $context = array()
	): ChatOutcome {
		if ( ! $conversation->acceptsAiReplies() ) {
			// A person is on this conversation, or has been asked for. The
			// visitor's words are still stored — they are what the
			// colleague is going to read — but the clerk does not answer
			// over the top of a request to speak to someone.
			$this->store( $conversation, MessageRole::Visitor, $message, array( 'awaiting_human' ) );
			$this->conversations->save( $conversation );

			$sink->done(
				array(
					'message_id'     => null,
					'tokens_in'      => 0,
					'tokens_out'     => 0,
					'grounded'       => false,
					'lead_captured'  => false,
					'awaiting_human' => true,
				)
			);

			return new ChatOutcome(
				messageId: '',
				text: '',
				flags: array( 'awaiting_human' ),
				blocked: true
			);
		}

		$input = $this->guardrails->validateInput( $agent, $message );

		if ( ! $input->allowed ) {
			// The visitor's message is still stored. A blocked message is
			// the most interesting row in the table for anyone asking why
			// a clerk refused, and discarding it leaves the transcript
			// showing a refusal with nothing before it.
			$this->store( $conversation, MessageRole::Visitor, $message, $input->flags );

			return $this->refuse( $agent, $conversation, $sink, $input->replacement, $input->flags );
		}

		$flags = $input->flags;

		if ( $this->guardrails->isExhausted( $conversation->messageCount ) ) {
			$this->store( $conversation, MessageRole::Visitor, $message, $flags );

			return $this->refuse(
				$agent,
				$conversation,
				$sink,
				$agent->fallbackText(),
				array_merge( $flags, array( 'conversation_cap' ) )
			);
		}

		if ( $agent->isBudgetBlocked() ) {
			$this->store( $conversation, MessageRole::Visitor, $message, $flags );

			// The visitor is shown the clerk's own fallback, not an error.
			// They did nothing wrong and cannot act on the real reason.
			return $this->refuse(
				$agent,
				$conversation,
				$sink,
				$agent->fallbackText(),
				array_merge( $flags, array( 'budget_exhausted' ) )
			);
		}

		if ( null === $agent->model() || null === $agent->provider() ) {
			$this->store( $conversation, MessageRole::Visitor, $message, $flags );

			return $this->refuse(
				$agent,
				$conversation,
				$sink,
				$agent->fallbackText(),
				array_merge( $flags, array( 'provider_unconfigured' ) )
			);
		}

		$this->store( $conversation, MessageRole::Visitor, $message, $flags );

		$sourceIds = null === $agent->id ? array() : $this->agents->sourceIds( $agent->id );
		$retrieved = array();
		$result    = null;

		if ( array() !== $sourceIds ) {
			$result = $this->retrieval->retrieve(
				$message,
				$sourceIds,
				RetrievalOptions::of( $agent->topK(), $agent->confidenceThreshold() )
			);

			$retrieved = $result->chunks;
		}

		/**
		 * Fires after retrieval has run for a visitor's message.
		 *
		 * Knowledge-gap detection attaches here rather than to
		 * `hiveclerk/chat/replied`, because this is the only point at
		 * which the question and what searching for it found are both in
		 * hand. Reconstructing them afterwards would mean two extra
		 * queries per reply to answer a question that was already
		 * answered here.
		 *
		 * Fired before the confidence check on purpose: a clerk that
		 * answers anyway from a weak match still has the gap, and it is
		 * the gap the operator most wants to know about.
		 *
		 * Nothing in the request path may depend on a listener.
		 *
		 * @param Agent                $agent        The clerk.
		 * @param Conversation         $conversation The conversation.
		 * @param string               $message      What the visitor asked.
		 * @param RetrievalResult|null $result       What was found, or null when the clerk has no sources.
		 */
		do_action( 'hiveclerk/chat/retrieved', $agent, $conversation, $message, $result );

		$confidence = $this->guardrails->checkConfidence( $agent, $retrieved, array() !== $sourceIds );

		if ( ! $confidence->allowed ) {
			return $this->refuse(
				$agent,
				$conversation,
				$sink,
				$confidence->replacement,
				array_merge( $flags, $confidence->flags ),
				$retrieved
			);
		}

		return $this->generate( $agent, $conversation, $message, $retrieved, $sink, $context, $flags );
	}

	/**
	 * Stream a completion and store what it produced.
	 *
	 * @param Agent                      $agent        The clerk.
	 * @param Conversation               $conversation The conversation.
	 * @param string                     $message      Visitor input.
	 * @param array<int, RetrievedChunk> $retrieved    Retrieved chunks.
	 * @param ChatSink                   $sink         Where the reply is written.
	 * @param array<string, mixed>       $context      Page context.
	 * @param array<int, string>         $flags        Flags carried from input checks.
	 * @return ChatOutcome
	 */
	private function generate(
		Agent $agent,
		Conversation $conversation,
		string $message,
		array $retrieved,
		ChatSink $sink,
		array $context,
		array $flags
	): ChatOutcome {
		$history = null === $conversation->id
			? array()
			: $this->messages->recent( $conversation->id, $agent->historyTurns() + 1 );

		// The message just stored is the one being answered; replaying it
		// as history would put it in the prompt twice, once as a prior turn
		// and once as the question.
		array_pop( $history );

		// Two facts the capture instructions need, computed here because
		// this is where they are already known. ChatService knows nothing
		// else about leads: whether to ask, what to ask and what to do with
		// the answer are all decided elsewhere.
		$context['visitor_messages'] = $this->visitorTurns( $history ) + 1;
		$context['lead_known']       = $conversation->hasLead();

		$prompt   = $this->prompts->build( $agent, $message, $history, $retrieved, $context );
		$uuid     = Uuid::generate();
		$started  = microtime( true );
		$buffer   = '';
		$aborted  = false;
		$failure  = null;
		$finished = null;

		$sink->start( $uuid->value, $conversation->uuid->value );

		try {
			$this->ai->stream(
				(string) $agent->provider(),
				$prompt->request,
				function ( StreamEvent $event ) use ( $sink, &$buffer, &$aborted, &$failure, &$finished ): bool {
					if ( StreamEvent::DELTA === $event->type ) {
						$buffer .= $event->text;

						if ( ! $sink->delta( $event->text ) ) {
							$aborted = true;

							return false;
						}

						return true;
					}

					if ( StreamEvent::ERROR === $event->type ) {
						$failure = $event->message;

						return false;
					}

					if ( StreamEvent::DONE === $event->type ) {
						$finished = $event->completion;

						if ( null !== $finished && '' === $buffer ) {
							// Some providers deliver the whole reply on the
							// terminal frame rather than as deltas.
							$buffer = $finished->text;
						}
					}

					return true;
				},
				UsageKind::Chat,
				$agent->id,
				$conversation->id
			);
		} catch ( ProviderException $e ) {
			$failure = $e->getMessage();
		}

		if ( null !== $failure && '' === trim( $buffer ) ) {
			$sink->error(
				'hvc_provider_error',
				"I can't reach my brain right now. Leave your email and we'll follow up.",
				true
			);

			return new ChatOutcome(
				messageId: $uuid->value,
				text: '',
				errorCode: 'hvc_provider_error'
			);
		}

		$verdict = $this->guardrails->validateOutput( $agent, $buffer, $prompt->request->system, $prompt->fence );
		$blocked = ! $verdict->allowed;
		$text    = $blocked ? $verdict->replacement : trim( $buffer );
		$flags   = array_merge( $flags, $verdict->flags );

		if ( null !== $failure ) {
			$flags[] = 'stream_interrupted';
		}

		if ( $blocked && ! $aborted ) {
			$sink->replace( $text );
		}

		$disclaimer = $agent->disclaimer();

		if ( ! $blocked && null !== $disclaimer && '' !== $text ) {
			// Appended by us rather than asked of the model. A required
			// disclaimer is a legal instrument; a model instructed to end
			// every reply with one complies almost always, and "almost" is
			// not a property to attach to a VAT notice. Sent as a final
			// delta so what the visitor read and what we stored match.
			$text .= "\n\n" . $disclaimer;

			if ( ! $aborted ) {
				$sink->delta( "\n\n" . $disclaimer );
			}
		}

		$citations = $blocked ? array() : $this->citationsFor( $agent, $prompt );
		$tokensIn  = null !== $finished ? $finished->tokensIn : 0;
		$tokensOut = null !== $finished ? $finished->tokensOut : 0;
		$cost      = null !== $finished ? (float) ( $finished->reportedCost ?? 0.0 ) : 0.0;

		$stored = $this->store(
			$conversation,
			MessageRole::Assistant,
			$text,
			$flags,
			array(
				'uuid'            => $uuid,
				'provider'        => $agent->provider(),
				'model'           => null !== $finished ? $finished->model : $agent->model(),
				'tokens_in'       => $tokensIn,
				'tokens_out'      => $tokensOut,
				'cost'            => $cost,
				'latency_ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'retrieval_score' => $this->bestScore( $prompt->grounding ),
				'is_grounded'     => ! $blocked && $prompt->isGrounded(),
			)
		);

		if ( null !== $stored->id && array() !== $citations ) {
			$this->citations->saveFor( $stored->id, $citations );
		}

		if ( null !== $agent->id && $tokensIn + $tokensOut > 0 ) {
			$this->agents->incrementUsage( $agent->id, $tokensIn + $tokensOut );
		}

		$this->tally( $conversation, $tokensIn, $tokensOut, $cost );

		$payloads = self::citationPayloads( $citations );

		if ( ! $aborted ) {
			if ( array() !== $payloads ) {
				$sink->citations( $payloads );
			}

			$sink->done(
				array(
					'message_id'    => $uuid->value,
					'tokens_in'     => $tokensIn,
					'tokens_out'    => $tokensOut,
					'grounded'      => ! $blocked && $prompt->isGrounded(),
					'lead_captured' => false,
				)
			);
		}

		$outcome = new ChatOutcome(
			messageId: $uuid->value,
			text: $text,
			citations: $payloads,
			tokensIn: $tokensIn,
			tokensOut: $tokensOut,
			grounded: ! $blocked && $prompt->isGrounded(),
			flags: $flags,
			blocked: $blocked
		);

		/**
		 * Fires after a clerk has replied and the reply has been stored.
		 *
		 * Lead extraction and knowledge-gap detection attach here in later
		 * sprints; nothing in the request path may depend on a listener.
		 *
		 * @param ChatOutcome  $outcome      What was produced.
		 * @param Conversation $conversation The conversation.
		 * @param Agent        $agent        The clerk.
		 */
		do_action( 'hiveclerk/chat/replied', $outcome, $conversation, $agent );

		return $outcome;
	}

	/**
	 * Deliver a canned reply without calling a provider.
	 *
	 * @param Agent                      $agent        The clerk.
	 * @param Conversation               $conversation The conversation.
	 * @param ChatSink                   $sink         Where the reply is written.
	 * @param string                     $text         Visitor-facing text.
	 * @param array<int, string>         $flags        Guardrail flags.
	 * @param array<int, RetrievedChunk> $retrieved    Chunks retrieved, if any.
	 * @return ChatOutcome
	 */
	private function refuse(
		Agent $agent,
		Conversation $conversation,
		ChatSink $sink,
		string $text,
		array $flags,
		array $retrieved = array()
	): ChatOutcome {
		$uuid = Uuid::generate();

		$sink->start( $uuid->value, $conversation->uuid->value );
		$sink->delta( $text );

		$this->store(
			$conversation,
			MessageRole::Assistant,
			$text,
			$flags,
			array(
				'uuid'            => $uuid,
				'retrieval_score' => $this->bestScore( $retrieved ),
			)
		);

		// A refusal costs nothing and still happened. Persisting the
		// conversation here is what advances message_count in storage, and
		// without it the conversation cap never counts a blocked message —
		// so the cheapest messages to send are the ones that never hit the
		// limit designed to stop them.
		$this->conversations->save( $conversation );

		$sink->done(
			array(
				'message_id'    => $uuid->value,
				'tokens_in'     => 0,
				'tokens_out'    => 0,
				'grounded'      => false,
				'lead_captured' => false,
			)
		);

		return new ChatOutcome(
			messageId: $uuid->value,
			text: $text,
			flags: $flags,
			blocked: true
		);
	}

	/**
	 * Persist one message and advance the conversation's counter.
	 *
	 * @param Conversation         $conversation The conversation.
	 * @param MessageRole          $role         Author.
	 * @param string               $content      Text.
	 * @param array<int, string>   $flags        Guardrail flags.
	 * @param array<string, mixed> $extra        Provider and scoring metadata.
	 * @return Message
	 */
	private function store(
		Conversation $conversation,
		MessageRole $role,
		string $content,
		array $flags = array(),
		array $extra = array()
	): Message {
		$uuid = $extra['uuid'] ?? null;

		$message = new Message(
			id: null,
			uuid: $uuid instanceof Uuid ? $uuid : Uuid::generate(),
			conversationId: (int) $conversation->id,
			role: $role,
			content: $content,
			provider: isset( $extra['provider'] ) && is_string( $extra['provider'] ) ? $extra['provider'] : null,
			model: isset( $extra['model'] ) && is_string( $extra['model'] ) ? $extra['model'] : null,
			tokensIn: (int) ( $extra['tokens_in'] ?? 0 ),
			tokensOut: (int) ( $extra['tokens_out'] ?? 0 ),
			cost: (float) ( $extra['cost'] ?? 0.0 ),
			latencyMs: isset( $extra['latency_ms'] ) ? (int) $extra['latency_ms'] : null,
			retrievalScore: isset( $extra['retrieval_score'] ) ? (float) $extra['retrieval_score'] : null,
			isGrounded: (bool) ( $extra['is_grounded'] ?? false ),
			guardrailFlags: array_values( array_unique( $flags ) )
		);

		$saved = $this->messages->save( $message );

		++$conversation->messageCount;
		$conversation->lastMessageAt = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		return $saved;
	}

	/**
	 * Add one exchange's usage to the conversation's running totals.
	 *
	 * @param Conversation $conversation The conversation.
	 * @param int          $tokensIn     Prompt tokens.
	 * @param int          $tokensOut    Completion tokens.
	 * @param float        $cost         Spend in USD.
	 * @return void
	 */
	private function tally( Conversation $conversation, int $tokensIn, int $tokensOut, float $cost ): void {
		$conversation->totalTokensIn  += $tokensIn;
		$conversation->totalTokensOut += $tokensOut;
		$conversation->totalCost      += $cost;

		$this->conversations->save( $conversation );
	}

	/**
	 * The citations for a reply.
	 *
	 * Only chunks that met the clerk's confidence threshold are cited. A
	 * citation is a claim that this passage supports the answer, and
	 * attaching one the retrieval itself scored as a poor match is the
	 * product lying about its own certainty in the one place a sceptical
	 * reader looks.
	 *
	 * @param Agent       $agent  The clerk.
	 * @param BuiltPrompt $prompt The assembled prompt.
	 * @return array<int, Citation>
	 */
	private function citationsFor( Agent $agent, BuiltPrompt $prompt ): array {
		$threshold = $agent->confidenceThreshold();
		$citations = array();
		$rank      = 0;

		foreach ( $prompt->grounding as $chunk ) {
			if ( ! $chunk->isConfident( $threshold ) ) {
				continue;
			}

			++$rank;

			$citations[] = new Citation(
				id: null,
				messageId: null,
				chunkId: $chunk->chunk->id,
				documentId: $chunk->chunk->documentId,
				score: $chunk->vectorScore,
				rank: $rank,
				title: $chunk->documentTitle,
				url: $chunk->documentUrl,
				headingPath: implode( ' > ', $chunk->chunk->headingPath ),
				excerpt: $chunk->excerpt( 180 )
			);

			if ( $rank >= self::MAX_CITATIONS ) {
				break;
			}
		}

		return $citations;
	}

	/**
	 * Citations in the shape the widget reads.
	 *
	 * @param array<int, Citation> $citations Citations.
	 * @return array<int, array<string, mixed>>
	 */
	public static function citationPayloads( array $citations ): array {
		return array_map(
			static fn ( Citation $citation ): array => array(
				'title'        => $citation->title,
				'url'          => $citation->url,
				'heading_path' => $citation->headingPath,
				'excerpt'      => $citation->excerpt,
				'score'        => round( $citation->score, 4 ),
			),
			$citations
		);
	}

	/**
	 * How many messages the visitor has sent in the replayed history.
	 *
	 * @param array<int, Message> $history Prior turns.
	 * @return int
	 */
	private function visitorTurns( array $history ): int {
		$turns = 0;

		foreach ( $history as $message ) {
			if ( MessageRole::Visitor === $message->role ) {
				++$turns;
			}
		}

		return $turns;
	}

	/**
	 * The best cosine score among a set of chunks.
	 *
	 * @param array<int, RetrievedChunk> $chunks Chunks.
	 * @return float|null
	 */
	private function bestScore( array $chunks ): ?float {
		$best = null;

		foreach ( $chunks as $chunk ) {
			if ( null === $best || $chunk->vectorScore > $best ) {
				$best = $chunk->vectorScore;
			}
		}

		return $best;
	}
}
