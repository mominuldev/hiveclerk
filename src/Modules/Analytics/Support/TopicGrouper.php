<?php
/**
 * Grouping questions into topics.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Analytics\Support;

/**
 * Collapses differently-worded questions about the same thing.
 *
 * ## Why this is not a model call
 *
 * Clustering a month of questions with an LLM would produce better
 * groups and a bill nobody agreed to, on a screen the customer opens
 * daily. SEC-03 names cost exhaustion as the cheaper attack than a
 * denial of service, and a report that spends money every time it is
 * rendered is that attack with the customer holding the trigger.
 *
 * ## What it actually does
 *
 * Lower-cases, strips punctuation, drops a small closed list of English
 * function words, folds regular plurals onto their singular, and keeps
 * the remaining words sorted. "Do you offer trade accounts?" and "trade
 * account?" both reduce to `account offer trade` and land in one row.
 *
 * The limits of that are real and worth stating rather than hiding. It is
 * English-only. It will not merge "returns" with "refunds", nor "ship"
 * with "shipping" — merging verb forms needs a stemmer, and a stemmer
 * also merges "rate" with "rating", which on a shop are two different
 * questions. And it will merge two genuinely different questions that
 * happen to share their content words.
 *
 * The screen labels each group with a real question a visitor asked
 * rather than with the reduced key, so a wrong grouping is visible to the
 * reader instead of being asserted as a category.
 */
final class TopicGrouper {

	/**
	 * Words carrying no topic on their own.
	 *
	 * Kept short on purpose. An aggressive stop list turns "how do I
	 * cancel" into "cancel" and merges it with "cancel my subscription",
	 * which is right, and then turns "is it in stock" into "stock" and
	 * merges it with "stock photos", which is not. Everything here is a
	 * function word that cannot be the subject of a question.
	 */
	private const STOP_WORDS = array(
		'a',
		'about',
		'am',
		'an',
		'and',
		'any',
		'anyone',
		'are',
		'as',
		'at',
		'be',
		'been',
		'but',
		'by',
		'can',
		'could',
		'did',
		'do',
		'does',
		'for',
		'from',
		'get',
		'got',
		'has',
		'have',
		'hi',
		'hey',
		'hello',
		'how',
		'i',
		'if',
		'in',
		'is',
		'it',
		'its',
		'just',
		'me',
		'my',
		'need',
		'of',
		'on',
		'or',
		'please',
		'she',
		'should',
		'so',
		'some',
		'thanks',
		'thank',
		'that',
		'the',
		'their',
		'them',
		'then',
		'there',
		'they',
		'this',
		'to',
		'up',
		'want',
		'was',
		'we',
		'what',
		'when',
		'where',
		'which',
		'who',
		'why',
		'will',
		'with',
		'would',
		'you',
		'your',
		'yours',
	);

	/**
	 * The key two questions must share to be counted together.
	 *
	 * Returns an empty string for anything with no content words left,
	 * which is how "hi there" and "thanks!" stay out of the report
	 * instead of becoming its top two entries.
	 *
	 * @param string $question Raw question text.
	 * @return string
	 */
	public static function key( string $question ): string {
		$words = self::words( $question );

		if ( array() === $words ) {
			return '';
		}

		// Sorted, so word order stops mattering: "EU delivery" and
		// "delivery to the EU" are the same question asked twice.
		sort( $words );

		return implode( ' ', array_unique( $words ) );
	}

	/**
	 * A readable label for a group, taken from a question in it.
	 *
	 * The visitor's own words rather than the reduced key, because
	 * `eu ship` is not something an operator can act on and "Do you ship
	 * to the EU?" is.
	 *
	 * @param string $question Raw question text.
	 * @return string
	 */
	public static function label( string $question ): string {
		$clean = trim( preg_replace( '/\s+/u', ' ', $question ) ?? $question );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $clean ) > 90 ) {
			return rtrim( mb_substr( $clean, 0, 89 ) ) . '…';
		}

		if ( strlen( $clean ) > 90 ) {
			return rtrim( substr( $clean, 0, 89 ) ) . '…';
		}

		return $clean;
	}

	/**
	 * Content words of a question, lower-cased.
	 *
	 * @param string $question Raw question.
	 * @return array<int, string>
	 */
	private static function words( string $question ): array {
		$lowered = function_exists( 'mb_strtolower' )
			? mb_strtolower( $question, 'UTF-8' )
			: strtolower( $question );

		$stripped = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $lowered );
		$parts    = preg_split( '/\s+/u', trim( (string) $stripped ) );

		if ( false === $parts ) {
			return array();
		}

		$kept = array_filter(
			$parts,
			static fn ( string $word ): bool =>
				'' !== $word
				&& strlen( $word ) > 1
				&& ! in_array( $word, self::STOP_WORDS, true )
		);

		return array_values( array_map( array( self::class, 'singular' ), $kept ) );
	}

	/**
	 * A regular plural folded onto its singular.
	 *
	 * The one word-shape change this class makes, and it is deliberately
	 * the least ambiguous one available: "account" and "accounts" are the
	 * same question, in every sentence, in a way that "ship" and
	 * "shipping" are not.
	 *
	 * Guarded on length and on a double-s ending so that "less", "class"
	 * and "gas" are left alone.
	 *
	 * @param string $word Content word.
	 * @return string
	 */
	private static function singular( string $word ): string {
		if ( strlen( $word ) < 4 || ! str_ends_with( $word, 's' ) || str_ends_with( $word, 'ss' ) ) {
			return $word;
		}

		if ( str_ends_with( $word, 'ies' ) ) {
			return substr( $word, 0, -3 ) . 'y';
		}

		if ( str_ends_with( $word, 'us' ) || str_ends_with( $word, 'is' ) ) {
			return $word;
		}

		return rtrim( $word, 's' );
	}
}
