<?php
/**
 * Reading contact details out of what a visitor typed.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Support;

use Hiveclerk\Domain\Lead\Lead;

/**
 * Patterns, not a model (FR-LED-01).
 *
 * A model would read "my colleague Dave in procurement suggested I get in
 * touch" and produce a lead called Dave. It would also cost a completion
 * on every visitor message, which is a second bill for the same
 * conversation and the one thing SEC-03 says not to add.
 *
 * The trade is deliberate and it is the conservative direction: this
 * finds addresses and phone numbers reliably, finds names and companies
 * only when the visitor states them outright, and finds nothing at all in
 * an ambiguous sentence. A missed name is a blank field an operator can
 * fill in. A wrong name is a lead they call by somebody else's.
 */
final class ContactExtractor {

	/**
	 * Characters of one message that are scanned.
	 *
	 * A pasted document is not a contact detail, and running six patterns
	 * over an unbounded string on every visitor message is a cost nobody
	 * chose.
	 */
	private const MAX_LENGTH = 4000;

	/**
	 * Words that are never a person's first name in these patterns.
	 *
	 * "I'm looking for a quote" and "I'm interested in the blue one" both
	 * match a naive "I'm <word>" rule, and both would produce a lead
	 * called Looking.
	 *
	 * @var array<int, string>
	 */
	private const NOT_NAMES = array(
		'looking',
		'interested',
		'trying',
		'wondering',
		'just',
		'not',
		'still',
		'here',
		'after',
		'about',
		'sorry',
		'happy',
		'going',
		'having',
		'from',
		'with',
		'a',
		'an',
		'the',
		'in',
		'on',
		'at',
		'to',
		'and',
		'but',
		'so',
		'very',
		'really',
		'currently',
		'afraid',
		'sure',
		'new',
		'old',
		'ok',
		'okay',
		'fine',
		'good',
	);

	/**
	 * Read what one message gives up.
	 *
	 * @param string $message What the visitor typed.
	 * @return ExtractedContact
	 */
	public function fromMessage( string $message ): ExtractedContact {
		$text = trim( mb_substr( $message, 0, self::MAX_LENGTH ) );

		if ( '' === $text ) {
			return new ExtractedContact();
		}

		[ $firstName, $lastName ] = $this->name( $text );

		return new ExtractedContact(
			email: $this->email( $text ),
			phone: $this->phone( $text ),
			firstName: $firstName,
			lastName: $lastName,
			company: $this->company( $text ),
			website: $this->website( $text ),
		);
	}

	/**
	 * Read a whole conversation, earliest statement winning.
	 *
	 * @param array<int, string> $messages Visitor messages, oldest first.
	 * @return ExtractedContact
	 */
	public function fromMessages( array $messages ): ExtractedContact {
		$found = new ExtractedContact();

		foreach ( $messages as $message ) {
			$found = $found->mergedWith( $this->fromMessage( $message ) );
		}

		return $found;
	}

	/**
	 * The first address in a message.
	 *
	 * Validated rather than merely matched. An address extracted from the
	 * middle of a sentence has never been near a form field, and a
	 * malformed one becomes an email hash that dedups against nothing and
	 * a follow-up that bounces.
	 *
	 * @param string $text Message text.
	 * @return string|null
	 */
	private function email( string $text ): ?string {
		if ( 1 !== preg_match( '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', $text, $match ) ) {
			return null;
		}

		// A trailing full stop belongs to the sentence, not the address.
		return Lead::normaliseEmail( rtrim( $match[0], '.' ) );
	}

	/**
	 * A phone number, when the message contains something that is clearly one.
	 *
	 * Deliberately strict about length. Order numbers, postcodes, prices
	 * and years all look like short digit runs, and a lead whose phone
	 * field holds "2024" is worse than one with an empty phone field.
	 *
	 * @param string $text Message text.
	 * @return string|null
	 */
	private function phone( string $text ): ?string {
		$pattern = '/(?<![\w.])(\+?\d[\d\s().-]{8,20}\d)(?![\w.])/u';

		if ( 1 !== preg_match( $pattern, $text, $match ) ) {
			return null;
		}

		$candidate = trim( $match[1] );
		$digits    = (string) preg_replace( '/\D+/', '', $candidate );

		if ( strlen( $digits ) < 9 || strlen( $digits ) > 15 ) {
			return null;
		}

		return mb_substr( $candidate, 0, 50 );
	}

	/**
	 * A name, when the visitor states one.
	 *
	 * @param string $text Message text.
	 * @return array{0: string|null, 1: string|null}
	 */
	private function name( string $text ): array {
		/*
		 * Two patterns with different strictness, on purpose.
		 *
		 * "My name is …" is unambiguous, so the name after it is taken
		 * whatever its casing — plenty of people type in lower case.
		 * "I'm …" is not unambiguous at all, so there the name has to
		 * start with a capital as well as survive the stop list; without
		 * that, "i'm after a quote" produces a lead called After.
		 */
		$patterns = array(
			'/\b(?i:my name(?:\'s| is)|i am called|call me)\s+([\p{L}][\p{L}\'-]{1,30})(?:\s+([\p{L}][\p{L}\'-]{1,30}))?/u',
			'/\b(?i:i\'m|i am|this is|it\'s)\s+([\p{Lu}][\p{L}\'-]{1,30})(?:\s+([\p{Lu}][\p{L}\'-]{1,30}))?\b/u',
		);

		foreach ( $patterns as $pattern ) {
			if ( 1 !== preg_match( $pattern, $text, $match ) ) {
				continue;
			}

			$first = trim( $match[1] );

			if ( in_array( mb_strtolower( $first ), self::NOT_NAMES, true ) ) {
				continue;
			}

			$last = isset( $match[2] ) ? trim( $match[2] ) : '';

			return array(
				$this->capitalise( $first ),
				'' === $last ? null : $this->capitalise( $last ),
			);
		}

		return array( null, null );
	}

	/**
	 * A name with its first letter in upper case.
	 *
	 * The operator reads this on a card and copies it into an email. A
	 * lead called "sarah" is one somebody has to fix by hand before they
	 * can use it.
	 *
	 * @param string $name Captured name.
	 * @return string
	 */
	private function capitalise( string $name ): string {
		return mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 );
	}

	/**
	 * An organisation, when the visitor names one.
	 *
	 * @param string $text Message text.
	 * @return string|null
	 */
	private function company( string $text ): ?string {
		// The captured name must start with a capital. "We're interested"
		// and "we are looking" are the two commonest sentences on any
		// site's chat widget and neither names a company.
		$pattern = '/\b(?i:i work (?:at|for)|we(?:\'re| are)|our company is|i\'m from|i am from|on behalf of)\s+'
			. '([\p{Lu}][\p{L}\p{N}&\'.\- ]{1,60}?)(?=[,.!?]|\s+(?i:and|but|so|who|which|that)\b|$)/u';

		if ( 1 !== preg_match( $pattern, $text, $match ) ) {
			return null;
		}

		$company = trim( $match[1] );

		// "We're interested" and "we are looking" are the two commonest
		// sentences on any site's chat widget, and neither names a company.
		$firstWord = mb_strtolower( (string) strtok( $company, ' ' ) );

		if ( '' === $company || in_array( $firstWord, self::NOT_NAMES, true ) ) {
			return null;
		}

		return mb_substr( $company, 0, 191 );
	}

	/**
	 * A website, when one appears.
	 *
	 * @param string $text Message text.
	 * @return string|null
	 */
	private function website( string $text ): ?string {
		if ( 1 !== preg_match( '#\bhttps?://[^\s<>"\']+#i', $text, $match ) ) {
			return null;
		}

		return mb_substr( rtrim( $match[0], '.,);' ), 0, 255 );
	}
}
