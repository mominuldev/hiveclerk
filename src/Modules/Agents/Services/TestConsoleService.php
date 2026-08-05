<?php
/**
 * Running a clerk in the admin, without the live site.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Agents\Services;

use Hiveclerk\Ai\AiServiceInterface;
use Hiveclerk\Ai\PricingTable;
use Hiveclerk\Ai\ProviderException;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\Message;
use Hiveclerk\Domain\Conversation\MessageRole;
use Hiveclerk\Domain\Knowledge\RetrievalOptions;
use Hiveclerk\Domain\Knowledge\RetrievalServiceInterface;
use Hiveclerk\Domain\Knowledge\RetrievedChunk;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Domain\Usage\UsageKind;
use Hiveclerk\Modules\Chat\Services\GuardrailService;
use Hiveclerk\Modules\Chat\Services\PromptBuilder;

/**
 * The test console (FR-CLK-08).
 *
 * It runs the same guardrails, the same retrieval and the same prompt
 * assembly as a real conversation, and stores none of it. A console that
 * wrote conversations would put the operator's own experiments into the
 * customer's transcripts, into their analytics and eventually into their
 * lead scoring — and an operator who cannot try a change without dirtying
 * the record stops trying changes.
 *
 * What it does not skip is metering. The call costs the customer money
 * whoever made it, so `UsageKind::Verify` records it against the site
 * without charging it to the clerk's monthly budget: a budget is a
 * promise about what visitors cost, and testing is not visitors.
 *
 * The diagnostics are the point of the screen rather than a debug aid.
 * Cost and groundedness on every run are the two numbers that decide
 * whether an operator trusts the clerk, and the dropped-chunk counts are
 * the only way to tell "retrieval found nothing" apart from "retrieval
 * found it and the budget cut it".
 */
final class TestConsoleService {

	/**
	 * Turns of history the console will replay.
	 *
	 * The console is for probing one exchange at a time. A long
	 * back-and-forth in a test panel is a conversation, and the place to
	 * have one is the widget.
	 */
	private const MAX_HISTORY = 12;

	/**
	 * Longest test message accepted.
	 */
	private const MAX_MESSAGE = 2000;

	/**
	 * Construct.
	 *
	 * @param AiServiceInterface        $ai        Model access.
	 * @param RetrievalServiceInterface $retrieval Knowledge search.
	 * @param PromptBuilder             $prompts   Prompt assembly.
	 * @param GuardrailService          $guardrails Input and output checks.
	 * @param AgentRepositoryInterface  $agents    Clerk storage.
	 * @param PricingTable              $pricing   Published model prices.
	 */
	public function __construct(
		private readonly AiServiceInterface $ai,
		private readonly RetrievalServiceInterface $retrieval,
		private readonly PromptBuilder $prompts,
		private readonly GuardrailService $guardrails,
		private readonly AgentRepositoryInterface $agents,
		private readonly PricingTable $pricing
	) {
	}

	/**
	 * Run one message through the clerk.
	 *
	 * @param Agent                            $agent   The clerk.
	 * @param string                           $message The question.
	 * @param array<int, array<string, mixed>> $history Prior turns as role/content pairs.
	 * @return array<string, mixed>
	 */
	public function run( Agent $agent, string $message, array $history = array() ): array {
		$message = mb_substr( trim( $message ), 0, self::MAX_MESSAGE );
		$flags   = array();

		$input = $this->guardrails->validateInput( $agent, $message );

		if ( ! $input->allowed ) {
			return $this->refusal( $input->replacement, $input->flags, $input->reason );
		}

		$flags = $input->flags;

		if ( null === $agent->provider() || null === $agent->model() ) {
			return $this->refusal(
				$agent->fallbackText(),
				array_merge( $flags, array( 'provider_unconfigured' ) ),
				__( 'This clerk has no model selected, so there is nothing to ask.', 'hiveclerk' )
			);
		}

		$sourceIds  = null === $agent->id ? array() : $this->agents->sourceIds( $agent->id );
		$retrieved  = array();
		$retrievalM = 0.0;
		$diagnostic = array();

		if ( array() !== $sourceIds ) {
			$started = microtime( true );

			$result = $this->retrieval->retrieve(
				$message,
				$sourceIds,
				RetrievalOptions::of( $agent->topK(), $agent->confidenceThreshold() )
			);

			$retrievalM = ( microtime( true ) - $started ) * 1000;
			$retrieved  = $result->chunks;
			$diagnostic = $result->diagnostics->toArray();
		}

		$confidence = $this->guardrails->checkConfidence( $agent, $retrieved, array() !== $sourceIds );

		if ( ! $confidence->allowed ) {
			// Not an error. This is the clerk behaving exactly as
			// configured, and the console has to show it that way or the
			// operator will spend the afternoon debugging a working
			// confidence threshold.
			return $this->refusal(
				$confidence->replacement,
				array_merge( $flags, $confidence->flags ),
				$confidence->reason,
				$retrieved,
				$retrievalM,
				$diagnostic
			);
		}

		$prompt = $this->prompts->build( $agent, $message, $this->history( $history ), $retrieved );

		$started = microtime( true );

		try {
			$completion = $this->ai->complete(
				(string) $agent->provider(),
				$prompt->request,
				UsageKind::Verify,
				$agent->id
			);
		} catch ( ProviderException $e ) {
			return array(
				'reply'       => '',
				'citations'   => array(),
				'error'       => $e->getMessage(),
				'diagnostics' => array_merge(
					$this->baseDiagnostics( $retrievalM, $retrieved, $diagnostic ),
					array(
						'completion_ms'        => (int) round( ( microtime( true ) - $started ) * 1000 ),
						'guardrails_triggered' => $flags,
						'prompt_tokens_est'    => $prompt->estimatedTokens,
					)
				),
			);
		}

		$completionMs = (int) round( ( microtime( true ) - $started ) * 1000 );
		$verdict      = $this->guardrails->validateOutput(
			$agent,
			$completion->text,
			$prompt->request->system,
			$prompt->fence
		);

		$blocked = ! $verdict->allowed;
		$reply   = $blocked ? $verdict->replacement : trim( $completion->text );
		$flags   = array_values( array_unique( array_merge( $flags, $verdict->flags ) ) );

		$cost = $completion->reportedCost ?? $this->pricing->cost(
			(string) $agent->provider(),
			$completion->model,
			$completion->tokensIn,
			$completion->tokensOut
		);

		return array(
			'reply'       => $reply,
			'citations'   => $this->citations( $agent, $blocked ? array() : $prompt->grounding ),
			'error'       => null,
			'diagnostics' => array_merge(
				$this->baseDiagnostics( $retrievalM, $retrieved, $diagnostic ),
				array(
					'completion_ms'        => $completionMs,
					'tokens_in'            => $completion->tokensIn,
					'tokens_out'           => $completion->tokensOut,
					'cost'                 => $cost,
					'grounded'             => ! $blocked && $prompt->isGrounded(),
					'blocked'              => $blocked,
					'guardrails_triggered' => $flags,
					'model'                => $completion->model,
					'provider'             => $completion->provider,
					'finish_reason'        => $completion->finishReason,
					'prompt_tokens_est'    => $prompt->estimatedTokens,
					'dropped_chunks'       => $prompt->droppedChunks,
					'dropped_turns'        => $prompt->droppedTurns,
					'prompt_preview'       => $this->preview( $prompt->request->system, $prompt->request->turns ),
				)
			),
		);
	}

	/**
	 * The shape returned when nothing was sent to a provider.
	 *
	 * @param string                     $reply      What the visitor would see.
	 * @param array<int, string>         $flags      Guardrail flags.
	 * @param string                     $reason     Why, for the operator.
	 * @param array<int, RetrievedChunk> $retrieved  Chunks found, if any.
	 * @param float                      $retrievalM Retrieval time.
	 * @param array<string, mixed>       $diagnostic Retrieval diagnostics.
	 * @return array<string, mixed>
	 */
	private function refusal(
		string $reply,
		array $flags,
		string $reason,
		array $retrieved = array(),
		float $retrievalM = 0.0,
		array $diagnostic = array()
	): array {
		return array(
			'reply'       => $reply,
			'citations'   => array(),
			'error'       => null,
			'diagnostics' => array_merge(
				$this->baseDiagnostics( $retrievalM, $retrieved, $diagnostic ),
				array(
					'completion_ms'        => 0,
					'tokens_in'            => 0,
					'tokens_out'           => 0,
					// No provider call was made, so this cost nothing. That
					// is a measured zero, not an unknown one.
					'cost'                 => 0.0,
					'grounded'             => false,
					'blocked'              => true,
					'guardrails_triggered' => array_values( array_unique( $flags ) ),
					'refused_because'      => $reason,
				)
			),
		);
	}

	/**
	 * Diagnostics that exist whether or not a provider was called.
	 *
	 * @param float                      $retrievalM Retrieval time in milliseconds.
	 * @param array<int, RetrievedChunk> $retrieved  Chunks found.
	 * @param array<string, mixed>       $diagnostic Retrieval diagnostics.
	 * @return array<string, mixed>
	 */
	private function baseDiagnostics( float $retrievalM, array $retrieved, array $diagnostic ): array {
		return array(
			'retrieval_ms' => (int) round( $retrievalM ),
			'chunks_found' => count( $retrieved ),
			'retrieval'    => $diagnostic,
		);
	}

	/**
	 * Citations in the shape the console shows.
	 *
	 * @param Agent                      $agent  The clerk.
	 * @param array<int, RetrievedChunk> $chunks Chunks that reached the model.
	 * @return array<int, array<string, mixed>>
	 */
	private function citations( Agent $agent, array $chunks ): array {
		$threshold = $agent->confidenceThreshold();
		$citations = array();

		foreach ( $chunks as $chunk ) {
			$citations[] = array(
				'chunk_id'     => $chunk->chunk->id,
				'document_id'  => $chunk->chunk->documentId,
				'title'        => $chunk->documentTitle,
				'url'          => $chunk->documentUrl,
				'heading_path' => implode( ' > ', $chunk->chunk->headingPath ),
				'excerpt'      => $chunk->excerpt( 240 ),
				'score'        => round( $chunk->vectorScore, 4 ),
				// Shown even when below the threshold, flagged rather than
				// hidden: "retrieval found this and did not trust it" is the
				// single most useful thing this screen can tell a person
				// tuning the number.
				'confident'    => $chunk->isConfident( $threshold ),
			);
		}

		return $citations;
	}

	/**
	 * The prompt as the operator may read it.
	 *
	 * Shown in full rather than redacted. It holds no key, no URL and no
	 * customer data by construction — that is control 5 of SEC-01 — and
	 * the reader is a user who already holds `manage_agents`, which is the
	 * capability that lets them rewrite it anyway.
	 *
	 * @param string                     $system System prompt.
	 * @param array<int, \Hiveclerk\Ai\ChatTurn> $turns Turns sent.
	 * @return string
	 */
	private function preview( string $system, array $turns ): string {
		$last  = end( $turns );
		$final = false === $last ? '' : $last->content;

		return mb_substr( $system . "\n\n---\n\n" . $final, 0, 8000 );
	}

	/**
	 * Turn request history into messages the prompt builder understands.
	 *
	 * Roles that are not "assistant" become visitor turns. The console's
	 * history comes from a browser, and a caller that could label a turn
	 * "system" could put instructions into the prompt with the authority
	 * of the site owner — the same hole SEC-01 closes for retrieved text.
	 *
	 * @param array<int, array<string, mixed>> $history Raw pairs.
	 * @return array<int, Message>
	 */
	private function history( array $history ): array {
		$messages = array();

		foreach ( array_slice( $history, -self::MAX_HISTORY ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$content = $entry['content'] ?? '';

			if ( ! is_string( $content ) || '' === trim( $content ) ) {
				continue;
			}

			$role = 'assistant' === ( $entry['role'] ?? '' ) ? MessageRole::Assistant : MessageRole::Visitor;

			$messages[] = new Message(
				id: null,
				uuid: Uuid::generate(),
				conversationId: 0,
				role: $role,
				content: mb_substr( trim( $content ), 0, self::MAX_MESSAGE )
			);
		}

		return $messages;
	}
}
