<?php
/**
 * A clerk's lead capture settings.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

/**
 * What a clerk is allowed to ask for, and when (FR-LED-01, 02).
 *
 * Capture is off until somebody turns it on. A clerk that starts asking
 * for email addresses because the plugin was updated is a clerk that
 * changed the customer's site behaviour without being told to, and the
 * first person to notice is a visitor.
 *
 * `askAfter` exists because the failure mode of this feature is asking
 * too early. A support clerk that demands an address before answering
 * the first question is a form wall wearing a chat interface, and it
 * produces junk addresses from people trying to get past it.
 */
final readonly class LeadCapture {

	/**
	 * Default number of visitor messages before an address is asked for.
	 */
	public const DEFAULT_ASK_AFTER = 2;

	/**
	 * Construct.
	 *
	 * @param bool                             $enabled     Whether this clerk captures at all.
	 * @param int                              $askAfter    Visitor messages before asking.
	 * @param bool                             $scanReplies Whether details are read out of what the visitor typed.
	 * @param string|null                      $consentText Marketing consent wording, when consent is asked for.
	 * @param array<int, QualificationQuestion> $questions  What to find out.
	 */
	public function __construct(
		public bool $enabled = false,
		public int $askAfter = self::DEFAULT_ASK_AFTER,
		public bool $scanReplies = true,
		public ?string $consentText = null,
		public array $questions = array(),
	) {
	}

	/**
	 * Build from the clerk's stored lead_config column.
	 *
	 * @param array<string, mixed> $stored Stored configuration.
	 * @return self
	 */
	public static function fromArray( array $stored ): self {
		$askAfter = isset( $stored['ask_after'] ) && is_numeric( $stored['ask_after'] )
			? (int) $stored['ask_after']
			: self::DEFAULT_ASK_AFTER;

		$consent = $stored['consent_text'] ?? null;
		$consent = is_string( $consent ) && '' !== trim( $consent ) ? substr( trim( $consent ), 0, 500 ) : null;

		return new self(
			enabled: (bool) ( $stored['enabled'] ?? false ),
			askAfter: max( 0, min( 20, $askAfter ) ),
			scanReplies: (bool) ( $stored['scan_replies'] ?? true ),
			consentText: $consent,
			questions: self::questions( $stored['questions'] ?? null ),
		);
	}

	/**
	 * Storage form, and what the editor reads back.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'enabled'      => $this->enabled,
			'ask_after'    => $this->askAfter,
			'scan_replies' => $this->scanReplies,
			'consent_text' => $this->consentText,
			'questions'    => array_map(
				static fn ( QualificationQuestion $question ): array => $question->toArray(),
				$this->questions
			),
		);
	}

	/**
	 * Whether the clerk should be asking for details on this turn.
	 *
	 * @param int  $visitorMessages How many messages the visitor has sent.
	 * @param bool $alreadyKnown    Whether an address is already on file.
	 * @return bool
	 */
	public function shouldAsk( int $visitorMessages, bool $alreadyKnown ): bool {
		if ( ! $this->enabled || $alreadyKnown ) {
			return false;
		}

		return $visitorMessages >= $this->askAfter;
	}

	/**
	 * The questions still unanswered, in order.
	 *
	 * @param array<string, mixed> $answers What is already known.
	 * @return array<int, QualificationQuestion>
	 */
	public function outstanding( array $answers ): array {
		$remaining = array();

		foreach ( $this->questions as $question ) {
			$value = $answers[ $question->key ] ?? null;

			if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
				$remaining[] = $question;
			}
		}

		return $remaining;
	}

	/**
	 * Whether any question was configured.
	 *
	 * @return bool
	 */
	public function hasQuestions(): bool {
		return array() !== $this->questions;
	}

	/**
	 * Clean the question list.
	 *
	 * @param mixed $value Raw stored questions.
	 * @return array<int, QualificationQuestion>
	 */
	private static function questions( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$questions = array();
		$seen      = array();

		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$question = QualificationQuestion::fromArray( $entry );

			if ( null === $question || isset( $seen[ $question->key ] ) ) {
				// Two questions sharing a key would overwrite each other's
				// answer, and the second one would look like it was never
				// asked.
				continue;
			}

			$seen[ $question->key ] = true;
			$questions[]            = $question;

			if ( count( $questions ) >= QualificationQuestion::MAX_QUESTIONS ) {
				break;
			}
		}

		return $questions;
	}
}
