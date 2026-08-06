<?php
/**
 * What an embedding will be used for.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai;

/**
 * Whether a text is being embedded as searchable content or as a search.
 *
 * Asymmetric embedding models place a short question and the long passage
 * that answers it in different regions of the same space, and they need to
 * be told which side of that asymmetry an input is on. Gemini takes it as
 * `taskType`; OpenAI's models are symmetric and ignore the distinction.
 * Before this enum existed there was no way to say "this is a question",
 * so visitor queries were embedded as documents — which is precisely the
 * mismatch the asymmetry exists to fix, applied in reverse.
 */
enum EmbeddingTask: string {

	/**
	 * Content being indexed for later retrieval.
	 */
	case Document = 'document';

	/**
	 * A question searching that content.
	 */
	case Query = 'query';
}
