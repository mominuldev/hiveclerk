<?php
/**
 * Background indexing.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Jobs;

use Hiveclerk\Core\Queue\AbstractJob;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
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
	 */
	public function __construct(
		private readonly IngestionService $ingestion,
		private readonly KnowledgeSourceRepositoryInterface $sources
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

		$this->ingestion->run( $source );
	}
}
