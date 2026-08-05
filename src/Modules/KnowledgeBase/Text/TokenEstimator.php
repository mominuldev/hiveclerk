<?php
/**
 * Token counting without a tokeniser.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Text;

/**
 * Estimates how many tokens a piece of text will cost.
 *
 * The exact answer requires the provider's own tokeniser, which differs
 * per model, ships as a multi-megabyte vocabulary, and would have to be
 * bundled five times over. For chunking, an estimate is sufficient — the
 * consequence of being wrong by ten percent is a chunk ten percent off
 * target, not a failure.
 *
 * Being wrong by a *factor* is a different matter, and that is what this
 * class is really for. The usual `strlen / 4` rule is calibrated on
 * English. Chinese, Japanese and Korean text runs closer to one token
 * per character, so the naive rule under-counts CJK content by three to
 * four times — and a chunk three times over budget is rejected by the
 * embedding endpoint or silently truncated by it, which loses the tail
 * of every long paragraph on the site.
 *
 * The estimate deliberately rounds up. Too-small chunks retrieve fine;
 * too-large chunks fail.
 */
final class TokenEstimator {

	/**
	 * Characters per token for alphabetic scripts.
	 */
	private const CHARS_PER_TOKEN = 4.0;

	/**
	 * Tokens per whitespace-delimited word.
	 *
	 * Sub-word tokenisers split longer and inflected words, so a word is
	 * usually a little more than one token.
	 */
	private const TOKENS_PER_WORD = 1.3;

	/**
	 * Characters counted as one token each.
	 */
	private const DENSE_SCRIPTS = '\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}';

	/**
	 * Estimate the token count of a string.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public function estimate( string $text ): int {
		if ( '' === trim( $text ) ) {
			return 0;
		}

		$dense = preg_match_all( '/[' . self::DENSE_SCRIPTS . ']/u', $text );
		$dense = false === $dense ? 0 : $dense;

		$rest = preg_replace( '/[' . self::DENSE_SCRIPTS . ']/u', '', $text );
		$rest = null === $rest ? $text : $rest;

		$characters = mb_strlen( $rest );
		$words      = preg_split( '/\s+/u', trim( $rest ), -1, PREG_SPLIT_NO_EMPTY );
		$wordCount  = false === $words ? 0 : count( $words );

		// Two estimates of the same text, and the larger one wins. Long
		// unbroken strings — a URL, a product code, minified markup — have
		// few words and many characters; ordinary prose is the reverse.
		// Taking the maximum keeps both cases on the safe side.
		$alphabetic = max(
			$characters / self::CHARS_PER_TOKEN,
			$wordCount * self::TOKENS_PER_WORD
		);

		return (int) ceil( $dense + $alphabetic );
	}

	/**
	 * How many characters roughly correspond to a token budget.
	 *
	 * Used to seek to an approximate position before snapping to a real
	 * boundary, so it only has to be close.
	 *
	 * @param int $tokens Budget.
	 * @return int
	 */
	public function charactersFor( int $tokens ): int {
		return (int) round( $tokens * self::CHARS_PER_TOKEN );
	}
}
