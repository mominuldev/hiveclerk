<?php
/**
 * Background indexing.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Jobs;

use Hiveclerk\Core\Queue\AbstractJob;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
use Hiveclerk\Domain\Knowledge\SourceStatus;
use Hiveclerk\Modules\KnowledgeBase\Services\IngestionService;

/**
 * Indexes one source, out of band.
 *
 * Indexing is never done inline. A crawl of a medium site takes minutes,
 * and the alternative to a job is an admin request that either times out
 * or holds a PHP worker for the duration — on shared hosting, where the
 * worker pool is small enough that one import can take the site down.
 */
final class IngestSourceJob extends AbstractJob {

	/**
	 * Construct.
	 *
	 * @param IngestionService                   $ingestion Ingestion.
	 * @param KnowledgeSourceRepositoryInterface $sources   Sources.
	 * @param QueueInterface                     $queue     Background queue.
	 */
	public function __construct(
		private readonly IngestionService $ingestion,
		private readonly KnowledgeSourceRepositoryInterface $sources,
		private readonly QueueInterface $queue
	) {
	}

	/**
	 * Hook name.
	 *
	 * @return string
	 */
	public static function hook(): string {
		return 'hiveclerk/job/ingest_source';
	}

	/**
	 * Index the source.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return void
	 */
	public function handle( array $args ): void {
		$sourceId = self::intArg( $args, 'source_id' );

		if ( $sourceId <= 0 ) {
			return;
		}

		$source = $this->sources->find( $sourceId );

		if ( null === $source ) {
			// Deleted between being queued and being run. Not an error:
			// the queue holds work for as long as it takes to reach it,
			// and a customer is entitled to change their mind in between.
			return;
		}

		$progress = $this->ingestion->run( $source );

		if ( 'error' === $progress->stage || 'cancelled' === $progress->stage ) {
			return;
		}

		$this->handOffToEmbedding( $sourceId );
	}

	/**
	 * Queue the embedding pass for a source that has just been chunked.
	 *
	 * Chunks are not searchable until they have vectors, and producing
	 * them is a different kind of work: extraction is bounded by the
	 * customer's own server, embedding is bounded by a third party's rate
	 * limit and costs money per token. Running them as one job means a
	 * provider outage halfway through loses the extraction as well, and
	 * the retry re-crawls a site that had not changed.
	 *
	 * @param int $sourceId Source.
	 * @return void
	 */
	private function handOffToEmbedding( int $sourceId ): void {
		$args = array( 'source_id' => $sourceId );

		if ( $this->queue->isPending( EmbedSourceJob::hook(), $args ) ) {
			return;
		}

		// Re-read: ingestion has just rewritten the row, and the in-memory
		// copy this job started with no longer describes it.
		$source = $this->sources->find( $sourceId );

		if ( null === $source || 0 === $source->chunkCount ) {
			return;
		}

		// The source stays busy until its vectors exist. Reporting "ready"
		// between chunking and embedding would be true of the text and
		// false of everything a clerk can actually do with it.
		$source->status = SourceStatus::Processing;

		$this->sources->save( $source );

		$this->queue->enqueue( EmbedSourceJob::hook(), $args );
	}
}
