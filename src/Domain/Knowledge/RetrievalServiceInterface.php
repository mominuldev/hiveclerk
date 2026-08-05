<?php
/**
 * The retrieval port.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * What a caller needs from retrieval, and nothing else.
 *
 * Declared in the domain so the chat module depends on a contract rather
 * than on the knowledge module's implementation. The two still ship
 * together — a clerk that cannot read the index is not a product — but the
 * direction of the dependency is what keeps retrieval replaceable, and it
 * is what lets the chat orchestration be tested without a vector store, an
 * embedding provider or a database.
 *
 * Narrower than the sketch in the API specification, which also lists
 * `explain()`. Diagnostics turned out to belong *with* the results rather
 * than beside them — see {@see RetrievalResult} — so there is one method
 * where the sketch has two.
 */
interface RetrievalServiceInterface {

	/**
	 * Retrieve the chunks most likely to answer a question.
	 *
	 * @param string           $query     The question.
	 * @param array<int, int>  $sourceIds Sources to search.
	 * @param RetrievalOptions $options   Parameters.
	 * @return RetrievalResult
	 */
	public function retrieve( string $query, array $sourceIds, RetrievalOptions $options ): RetrievalResult;
}
