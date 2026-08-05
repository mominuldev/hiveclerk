<?php
/**
 * Working out which question a visitor just answered.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Support;

use Hiveclerk\Domain\Lead\QualificationQuestion;

/**
 * Pairs a visitor's reply with the question the clerk asked before it.
 *
 * ## Why this is not a model call
 *
 * The obvious implementation asks the model to extract structured fields
 * from the transcript. It works, and it bills a second completion for
 * every message of every conversation — on the same customer key, for a
 * job that a bag of words solves most of the time.
 *
 * The cheap version works because of something the prompt already
 * guarantees: the clerk is instructed to ask for one thing at a time. So
 * the assistant turn immediately before a visitor's reply is, when it
 * contains a question at all, *the* question. Matching it against the
 * configured wording by word overlap is enough to know which one.
 *
 * ## What it gets wrong, on purpose
 *
 * A clerk that heavily paraphrases — "and roughly what were you hoping
 * to spend?" against a question configured as "What is your budget?" —
 * shares only stopwords and will not match. The answer is still in the
 * transcript and the operator can still read it; what is missing is the
 * structured field. That is the right failure: an unmatched answer costs
 * a blank cell, and a wrongly matched one puts a timeline into a budget
 * field and scores the lead on it.
 */
final class AnswerMatcher {

	/**
	 * Fraction of a question's significant words the clerk must have used.
	 *
	 * Two thirds. High enough that "what is your budget" does not match a
	 * question about timelines, low enough to survive the clerk adding a
	 * preamble, which it always does.
	 */
	private const MIN_OVERLAP = 0.66;

	/**
	 * Words carrying no signal about which question was asked.
	 *
	 * @var array<int, string>
	 */
	private const STOPWORDS = array(
		'a',
		'an',
		'the',
		'is',
		'are',
		'was',
		'were',
		'be',
		'been',
		'do',
		'does',
		'did',
		'you',
		'your',
		'yours',
		'we',
		'our',
		'i',
		'my',
		'me',
		'it',
		'this',
		'that',
		'what',
		'which',
		'who',
		'whom',
		'how',
		'when',
		'where',
		'why',
		'and',
		'or',
		'of',
		'in',
		'on',
		'at',
		'to',
		'for',
		'with',
		'about',
		'from',
		'by',
		'as',
		'have',
		'has',
		'had',
		'can',
		'could',
		'would',
		'will',
		'shall',
		'should',
		'may',
		'might',
		'please',
		'just',
		'so',
		'if',
		'any',
		'some',
		'there',
	);

	/**
	 * Which of these questions the clerk's message asked, if any.
	 *
	 * @param string                            $askedText What the clerk said.
	 * @param array<int, QualificationQuestion> $questions Outstanding questions.
	 * @return QualificationQuestion|null
	 */
	public function questionAsked( string $askedText, array $questions ): ?QualificationQuestion {
		if ( ! str_contains( $askedText, '?' ) || array() === $questions ) {
			// No question mark, no question. A clerk that answered without
			// asking has not set up an answer for the next turn to be.
			return null;
		}

		$asked = $this->words( $askedText );

		if ( array() === $asked ) {
			return null;
		}

		$best  = null;
		$score = self::MIN_OVERLAP;

		foreach ( $questions as $question ) {
			$wanted = $this->words( $question->question );

			if ( array() === $wanted ) {
				continue;
			}

			$overlap = count( array_intersect( $wanted, $asked ) ) / count( $wanted );

			if ( $overlap >= $score ) {
				$score = $overlap;
				$best  = $question;
			}
		}

		return $best;
	}

	/**
	 * Whether a reply reads as an answer rather than a deflection.
	 *
	 * "No thanks" and "why do you need that?" are both replies to a
	 * question and neither is an answer to it. Storing them would put
	 * "no thanks" in a budget field, which then scores.
	 *
	 * @param string $reply What the visitor typed.
	 * @return bool
	 */
	public function isAnswer( string $reply ): bool {
		$trimmed = trim( $reply );

		if ( '' === $trimmed || mb_strlen( $trimmed ) > 300 ) {
			return false;
		}

		if ( str_contains( $trimmed, '?' ) ) {
			return false;
		}

		$refusals = array(
			'no',
			'no thanks',
			'no thank you',
			'not now',
			'nope',
			'skip',
			'later',
			'rather not',
			'i\'d rather not',
			'prefer not to say',
			'n/a',
			'na',
			'none',
		);

		return ! in_array( mb_strtolower( rtrim( $trimmed, '.!' ) ), $refusals, true );
	}

	/**
	 * The significant words of a sentence.
	 *
	 * @param string $text Sentence.
	 * @return array<int, string>
	 */
	private function words( string $text ): array {
		$lower = mb_strtolower( $text );
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) ) {
			return array();
		}

		$words = array();

		foreach ( $parts as $word ) {
			if ( mb_strlen( $word ) < 3 || in_array( $word, self::STOPWORDS, true ) ) {
				continue;
			}

			$words[] = $word;
		}

		return array_values( array_unique( $words ) );
	}
}
