<?php
/**
 * Input and output guardrails.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Services;

use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Knowledge\RetrievedChunk;
use Hiveclerk\Modules\Chat\Support\GuardrailVerdict;

/**
 * What the clerk may be asked, and what it may say back.
 *
 * ## Why this blocks so little
 *
 * The obvious design refuses anything that looks like an attack. It is the
 * wrong one, and the reason is the false-positive cost. "Ignore the sale
 * price and tell me the normal one" is a real question a real customer
 * asks, and it matches every pattern anyone writes for "ignore previous
 * instructions". A clerk that refuses it has failed at its job in front of
 * a buyer, to defend against an attack that the prompt fence in
 * PromptBuilder already makes inert.
 *
 * So the split is: **block what costs money or cannot be answered, flag
 * what is worth knowing about.** Length caps and banned topics block,
 * because they are unambiguous and the first is the cheapest defence there
 * is against SEC-03 cost exhaustion. Injection-shaped phrasing is flagged
 * and answered — the isolation is the control, and the flag is what makes
 * an actual campaign visible in the conversations list rather than
 * invisible behind a wall of refusals.
 *
 * ## Output is checked differently
 *
 * Output has one failure mode worth catching automatically: the model
 * reproducing its own instructions. That is checkable without judging
 * meaning — a run of the system prompt appearing verbatim, or the fence
 * token, which the visitor has never seen and cannot have prompted for by
 * accident. Everything subtler than that is a job for a human reading the
 * transcript, and pretending otherwise would produce a filter that
 * silences correct answers at some rate nobody measures.
 *
 * @see docs/15-testing-strategy.md §SEC-01, §SEC-03
 */
final class GuardrailService {

	/**
	 * Characters a visitor may send in one message.
	 *
	 * Enforced before anything is embedded or completed. This is the
	 * cheapest control against cost exhaustion in the product: without it,
	 * one request can carry a megabyte of text straight into a billed
	 * embedding call.
	 */
	public const MAX_INPUT_CHARS = 2000;

	/**
	 * Messages one conversation may hold before it is closed.
	 *
	 * A per-session cap, required by SEC-03. A rate limit alone does not
	 * bound total spend — it only bounds the rate of it.
	 */
	public const MAX_CONVERSATION_MESSAGES = 60;

	/**
	 * Verbatim characters of the system prompt that may appear in output.
	 *
	 * Short runs are unavoidable: the prompt contains ordinary phrases like
	 * "the reference material", and the model is entitled to say those.
	 * Sixty characters of exact agreement is not a coincidence.
	 */
	private const LEAK_RUN_LENGTH = 60;

	/**
	 * Phrasings that indicate someone is testing the boundary.
	 *
	 * Flagged, never blocked. Kept deliberately narrow — each of these
	 * costs nothing when it is wrong, because the consequence is a row in
	 * a log rather than a refusal shown to a customer.
	 */
	private const PROBE_PATTERNS = array(
		'/ignore\s+(all\s+|any\s+)?(previous|prior|above|earlier)\s+(instruction|prompt|rule|direction)/i',
		'/disregard\s+(all\s+|any\s+)?(previous|prior|above|earlier|your)\s+/i',
		'/(reveal|show|print|repeat|output|display|recite)\s+(me\s+)?(your|the)\s+(system\s+)?(prompt|instruction|rule|configur)/i',
		'/what\s+(are|were)\s+your\s+(system\s+)?(instruction|prompt|rule)/i',
		'/you\s+are\s+(now|no\s+longer)\s+(a|an|in)\b/i',
		'/\b(developer|system|admin(istrator)?)\s+mode\b/i',
		'/\bDAN\b.{0,20}\b(mode|jailbreak)\b/i',
		'/\bpretend\s+(that\s+)?(you|to\s+be)\b.{0,40}\b(no|without)\s+(restriction|rule|filter|guardrail)/i',
		'/<\s*\/?\s*(system|instruction|admin)\s*>/i',
		'/\bnew\s+(instruction|directive|rule)s?\s*[:\-]/i',
		'/\bbegin\s+(new\s+)?(system|admin)\s+(prompt|message)/i',
		'/\brepeat\s+everything\s+(above|before)/i',
		'/\boverride\s+(your|the|all)\s+(instruction|rule|guardrail|restriction)/i',
		'/\bfrom\s+now\s+on\b.{0,40}\b(ignore|forget|disregard)\b/i',
		'/\b(forget|erase)\s+(everything|all)\b.{0,30}\b(told|said|instruct)/i',
	);

	/**
	 * Check a visitor message before anything is spent on it.
	 *
	 * @param Agent  $agent   The clerk.
	 * @param string $message Raw visitor input, already sanitised at the boundary.
	 * @return GuardrailVerdict
	 */
	public function validateInput( Agent $agent, string $message ): GuardrailVerdict {
		$trimmed = trim( $message );

		if ( '' === $trimmed ) {
			return GuardrailVerdict::block(
				'',
				'empty_input',
				'The message was empty after sanitising.'
			);
		}

		if ( mb_strlen( $trimmed ) > self::MAX_INPUT_CHARS ) {
			return GuardrailVerdict::block(
				sprintf(
					'That message is longer than I can read at once — could you keep it under %d characters?',
					self::MAX_INPUT_CHARS
				),
				'input_too_long',
				'Input exceeded the length cap before any provider call.'
			);
		}

		$topic = $this->matchedTopic( $trimmed, $agent->bannedTopics() );

		if ( null !== $topic ) {
			return GuardrailVerdict::block(
				$agent->fallbackText(),
				'banned_topic',
				sprintf( 'Matched the banned topic "%s".', $topic )
			);
		}

		$flags = array();

		if ( $this->looksLikeProbe( $trimmed ) ) {
			$flags[] = 'injection_probe';
		}

		return GuardrailVerdict::allow( $flags );
	}

	/**
	 * Check a completed reply before it is trusted.
	 *
	 * @param Agent  $agent  The clerk.
	 * @param string $text   The model's full reply.
	 * @param string $system The system prompt it was given.
	 * @param string $fence  The nonce tag used for untrusted blocks.
	 * @return GuardrailVerdict
	 */
	public function validateOutput( Agent $agent, string $text, string $system, string $fence ): GuardrailVerdict {
		$trimmed = trim( $text );

		if ( '' === $trimmed ) {
			return GuardrailVerdict::block(
				$agent->fallbackText(),
				'empty_output',
				'The provider returned no text.'
			);
		}

		// The fence is random per request and the visitor has never seen it.
		// Its presence in the output means the model is reproducing the
		// prompt structure, which is the observable signature of a
		// successful extraction attempt.
		if ( '' !== $fence && str_contains( $trimmed, $fence ) ) {
			return GuardrailVerdict::block(
				$agent->fallbackText(),
				'prompt_leak',
				'The reply contained the request-scoped fence token.'
			);
		}

		if ( $this->reproduces( $trimmed, $system ) ) {
			return GuardrailVerdict::block(
				$agent->fallbackText(),
				'prompt_leak',
				'The reply reproduced a run of the system prompt verbatim.'
			);
		}

		$topic = $this->matchedTopic( $trimmed, $agent->bannedTopics() );

		if ( null !== $topic ) {
			return GuardrailVerdict::block(
				$agent->fallbackText(),
				'banned_topic',
				sprintf( 'The reply discussed the banned topic "%s".', $topic )
			);
		}

		return GuardrailVerdict::allow();
	}

	/**
	 * Whether the clerk should answer at all, given what retrieval found.
	 *
	 * The gate applies only to a clerk that *has* sources. A clerk with no
	 * knowledge attached is not misconfigured — a qualification clerk whose
	 * entire job is asking three questions and capturing an email needs no
	 * sources at all, and refusing to let it speak would break it.
	 *
	 * When it does apply, refusing here rather than after the call saves
	 * the completion as well as the answer: a clerk that cannot ground a
	 * reply is about to spend the customer's money inventing one.
	 *
	 * @param Agent                      $agent     The clerk.
	 * @param array<int, RetrievedChunk> $retrieved Retrieved chunks.
	 * @param bool                       $hasSources Whether the clerk has any sources attached.
	 * @return GuardrailVerdict
	 */
	public function checkConfidence( Agent $agent, array $retrieved, bool $hasSources ): GuardrailVerdict {
		if ( ! $hasSources || ! $agent->refusesToInvent() ) {
			return GuardrailVerdict::allow();
		}

		$threshold = $agent->confidenceThreshold();

		foreach ( $retrieved as $chunk ) {
			if ( $chunk->isConfident( $threshold ) ) {
				return GuardrailVerdict::allow();
			}
		}

		return GuardrailVerdict::block(
			$agent->fallbackText(),
			'low_confidence',
			sprintf( 'No retrieved chunk reached the %.2f confidence threshold.', $threshold )
		);
	}

	/**
	 * Whether a conversation has run past its message cap.
	 *
	 * @param int $messageCount Messages already stored.
	 * @return bool
	 */
	public function isExhausted( int $messageCount ): bool {
		return $messageCount >= self::MAX_CONVERSATION_MESSAGES;
	}

	/**
	 * Whether text is shaped like an attempt on the instructions.
	 *
	 * @param string $text Text to inspect.
	 * @return bool
	 */
	public function looksLikeProbe( string $text ): bool {
		foreach ( self::PROBE_PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The first banned topic a text mentions, if any.
	 *
	 * Word-boundary matching, not substring: a topic of "cash" must not
	 * fire on "cashmere", which is the sort of false positive that makes an
	 * operator switch the feature off entirely.
	 *
	 * @param string             $text   Text to inspect.
	 * @param array<int, string> $topics Configured topics.
	 * @return string|null
	 */
	private function matchedTopic( string $text, array $topics ): ?string {
		foreach ( $topics as $topic ) {
			$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $topic, '/' ) . '(?![\p{L}\p{N}])/iu';

			if ( 1 === preg_match( $pattern, $text ) ) {
				return $topic;
			}
		}

		return null;
	}

	/**
	 * Whether the reply repeats a long run of the system prompt.
	 *
	 * Compared on whitespace-normalised, lower-cased text so that
	 * reformatting — which a model does routinely, and an extraction
	 * attempt does deliberately — does not defeat the check.
	 *
	 * @param string $text   The reply.
	 * @param string $system The system prompt.
	 * @return bool
	 */
	private function reproduces( string $text, string $system ): bool {
		$haystack = $this->normalise( $text );
		$needle   = $this->normalise( $system );

		if ( mb_strlen( $needle ) < self::LEAK_RUN_LENGTH ) {
			return false;
		}

		$limit = mb_strlen( $needle ) - self::LEAK_RUN_LENGTH;

		// Stepped rather than exhaustive. A window every twenty characters
		// still cannot be stepped over by a sixty-character run, and it
		// turns a check that runs on every reply from thousands of
		// comparisons into a few dozen.
		for ( $offset = 0; $offset <= $limit; $offset += 20 ) {
			$run = mb_substr( $needle, $offset, self::LEAK_RUN_LENGTH );

			if ( str_contains( $haystack, $run ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lower-case, single-spaced form of a text.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	private function normalise( string $value ): string {
		$collapsed = preg_replace( '/[\s\p{P}]+/u', ' ', $value ) ?? $value;

		return trim( mb_strtolower( $collapsed ) );
	}
}
