<?php
/**
 * Content extraction contract.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors;

use Hiveclerk\Domain\Knowledge\KnowledgeSource;
use Hiveclerk\Domain\Knowledge\SourceType;

/**
 * Pulls documents out of one kind of source.
 *
 * Extractors are the only part of the knowledge pipeline that knows
 * anything about where content came from. Everything downstream —
 * chunking, embedding, retrieval — sees an ExtractedDocument and cannot
 * tell a WooCommerce product from a page of a PDF.
 *
 * ## Extractors yield, they do not return
 *
 * `extract()` is a generator, and that is load-bearing rather than
 * stylistic. A crawl of a large site or a 900-page manual produces more
 * text than PHP's memory limit will hold, and a shared host's limit is
 * often 128 MB with WordPress already occupying a third of it. Returning
 * an array means the whole source is resident at once; yielding means one
 * document is.
 *
 * ## Extractors do not decide when to stop
 *
 * Caps, budgets and cancellation belong to IngestionService, which is the
 * only place that can see the whole job. An extractor that enforced its
 * own limit would enforce a different one from every other extractor.
 */
interface ExtractorInterface {

	/**
	 * Which source type this handles.
	 *
	 * @return SourceType
	 */
	public function type(): SourceType;

	/**
	 * Whether this extractor can run right now.
	 *
	 * WooCommerce may not be installed; a PDF parser may be missing. A
	 * source of an unavailable type reports an error the operator can act
	 * on rather than silently indexing nothing.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool;

	/**
	 * Why the extractor is unavailable, for the operator.
	 *
	 * @return string Empty when available.
	 */
	public function unavailableReason(): string;

	/**
	 * Estimate how many documents a source will produce.
	 *
	 * Used for the progress bar and the crawl preview's cost estimate. An
	 * estimate is allowed to be wrong; returning null when it genuinely
	 * cannot be known is better than guessing, because a progress bar
	 * that goes backwards reads as a broken import.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return int|null
	 */
	public function estimate( KnowledgeSource $source ): ?int;

	/**
	 * Produce documents.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return iterable<int, ExtractedDocument>
	 */
	public function extract( KnowledgeSource $source ): iterable;
}
