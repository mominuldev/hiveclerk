<?php
/**
 * Background embedding.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Jobs;

use Hiveclerk\Ai\EmbeddingModel;
use Hiveclerk\Ai\ProviderException;
use Hiveclerk\Core\Queue\AbstractJob;
use Hiveclerk\Core\Queue\QueueInterface;
use Hiveclerk\Domain\Knowledge\EmbeddingRepositoryInterface;
use Hiveclerk\Domain\Knowledge\ChunkRepositoryInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSource;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
use Hiveclerk\Domain\Knowledge\SourceStatus;
use Hiveclerk\Domain\Knowledge\VectorStoreInterface;
use Hiveclerk\Modules\KnowledgeBase\Services\EmbeddingService;
use Hiveclerk\Modules\KnowledgeBase\Services\IngestionProgress;
use Hiveclerk\Modules\KnowledgeBase\Vector\VectorCodec;
use Throwable;

/**
 * Embeds one source, a bounded batch at a time.
 *
 * The job re-enqueues itself while work remains rather than looping until
 * done. A five-thousand-chunk site is fifty provider round trips at a
 * second or two each — well past any `max_execution_time` a shared host
 * allows, and a job killed at the ninety-second mark leaves no record of
 * how far it got. Re-enqueueing after each batch means a kill costs one
 * batch, and the next run resumes from the first chunk that has no vector.
 *
 * "Which chunks have no vector" is a query, not a cursor. That is what
 * makes the job idempotent under the conditions it actually runs in: a
 * cron overlap, a manual retry, a host that ran the same scheduled action
 * twice. There is no offset to get wrong.
 */
final class EmbedSourceJob extends AbstractJob {

	/**
	 * Chunks embedded per run.
	 *
	 * Four provider calls of ninety-six. Sized so a run finishes inside
	 * roughly twenty seconds even when the provider is slow, which keeps
	 * it within a 30-second execution limit with headroom.
	 */
	private const PER_RUN = 384;

	/**
	 * Construct.
	 *
	 * @param EmbeddingService                   $embedder   Embedding.
	 * @param VectorStoreInterface               $vectors    Vector storage.
	 * @param EmbeddingRepositoryInterface       $embeddings Vector persistence.
	 * @param ChunkRepositoryInterface           $chunks     Chunks.
	 * @param KnowledgeSourceRepositoryInterface $sources    Sources.
	 * @param QueueInterface                     $queue      Queue.
	 */
	public function __construct(
		private readonly EmbeddingService $embedder,
		private readonly VectorStoreInterface $vectors,
		private readonly EmbeddingRepositoryInterface $embeddings,
		private readonly ChunkRepositoryInterface $chunks,
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
		return 'hiveclerk/job/embed_source';
	}

	/**
	 * Embed one batch and re-enqueue if more remains.
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
			return;
		}

		$pin = $this->pin( $source );

		if ( null === $pin ) {
			$this->fail(
				$source,
				'No embedding provider is configured, so this content cannot be searched by meaning. '
				. 'Add an OpenAI, Google or Azure key under Settings and re-index.'
			);

			return;
		}

		try {
			$done = $this->embedBatch( $source, $pin );
		} catch ( ProviderException $e ) {
			$this->fail( $source, $e->getMessage() );

			return;
		} catch ( Throwable $e ) {
			$this->fail( $source, 'Embedding failed: ' . $e->getMessage() );

			return;
		}

		if ( ! $done ) {
			$this->queue->enqueue( self::hook(), array( 'source_id' => $sourceId ) );

			return;
		}

		$this->finish( $source, $pin );
	}

	/**
	 * Embed up to one run's worth of chunks.
	 *
	 * @param KnowledgeSource $source Source.
	 * @param EmbeddingModel  $pin    Provider and model.
	 * @return bool Whether the source is fully embedded.
	 *
	 * @throws ProviderException When the provider fails unrecoverably.
	 */
	private function embedBatch( KnowledgeSource $source, EmbeddingModel $pin ): bool {
		$sourceId  = (int) $source->id;
		$processed = 0;

		while ( $processed < self::PER_RUN ) {
			$pending = $this->embeddings->pendingChunkIds(
				$sourceId,
				$pin->provider,
				$pin->model,
				EmbeddingService::BATCH
			);

			if ( array() === $pending ) {
				return true;
			}

			$chunks = $this->chunks->findMany( $pending );

			if ( array() === $chunks ) {
				return true;
			}

			$vectors = $this->embedder->embedChunks( $chunks, $pin );

			if ( array() === $vectors ) {
				// Every chunk in the batch was refused individually — an
				// unusual but real case, and one that must not spin: the
				// same ids would come back pending on the next pass and the
				// job would re-enqueue itself forever.
				return true;
			}

			$rows = array();

			foreach ( $vectors as $chunkId => $vector ) {
				$rows[] = VectorCodec::encode( $vector, (int) $chunkId, $sourceId );
			}

			$this->vectors->upsertMany( $rows );

			$processed += count( $rows );

			$this->publish( $source, $pin, $sourceId );
		}

		return 0 === $this->embeddings->countPending( $sourceId, $pin->provider, $pin->model );
	}

	/**
	 * Which model to embed with.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return EmbeddingModel|null
	 */
	private function pin( KnowledgeSource $source ): ?EmbeddingModel {
		$pinned = EmbeddingModel::fromStorage(
			$source->embedProvider,
			$source->embedModel,
			$source->embedDimensions
		);

		if ( null !== $pinned ) {
			return $pinned;
		}

		return $this->embedder->configured();
	}

	/**
	 * Write progress the knowledge screen can read.
	 *
	 * @param KnowledgeSource $source   Source.
	 * @param EmbeddingModel  $pin      Provider and model.
	 * @param int             $sourceId Source id.
	 * @return void
	 */
	private function publish( KnowledgeSource $source, EmbeddingModel $pin, int $sourceId ): void {
		$remaining = $this->embeddings->countPending( $sourceId, $pin->provider, $pin->model );
		$total     = $this->chunks->countForSource( $sourceId );

		$progress = new IngestionProgress( stage: 'embedding' );

		$progress->total     = $total;
		$progress->processed = max( 0, $total - $remaining );
		$progress->chunks    = $total;
		$progress->current   = sprintf( '%s · %s', $pin->provider, $pin->model );

		$source->status   = SourceStatus::Processing;
		$source->progress = $progress->toArray();

		$this->sources->save( $source );
	}

	/**
	 * Record a finished source, pinning what produced its vectors.
	 *
	 * @param KnowledgeSource $source Source.
	 * @param EmbeddingModel  $pin    Provider and model.
	 * @return void
	 */
	private function finish( KnowledgeSource $source, EmbeddingModel $pin ): void {
		$sourceId = (int) $source->id;
		$stored   = $this->embeddings->modelsForSource( $sourceId );
		$width    = 0;

		foreach ( $stored as $entry ) {
			if ( $entry['provider'] === $pin->provider && $entry['model'] === $pin->model ) {
				$width = $entry['dimensions'];

				break;
			}
		}

		$progress = new IngestionProgress( stage: 'done' );

		$progress->total     = $this->chunks->countForSource( $sourceId );
		$progress->processed = $progress->total;
		$progress->chunks    = $progress->total;

		$source->embedProvider   = $pin->provider;
		$source->embedModel      = $pin->model;
		$source->embedDimensions = $width > 0 ? $width : null;
		$source->status          = SourceStatus::Ready;
		$source->lastError       = null;
		$source->progress        = $progress->toArray();

		$this->sources->save( $source );

		// Dropped after the pin is stored, not before. A search racing this
		// method must either see the old matrix with the old pin or the new
		// matrix with the new one, never a new pin against a stale matrix.
		$this->vectors->invalidate( array( $sourceId ) );

		/**
		 * Fires when a source's vectors are complete and searchable.
		 *
		 * @param KnowledgeSource $source The source.
		 * @param EmbeddingModel  $pin    What produced the vectors.
		 */
		do_action( 'hiveclerk/knowledge/embedded', $source, $pin );
	}

	/**
	 * Record a source that could not be embedded.
	 *
	 * @param KnowledgeSource $source  Source.
	 * @param string          $message What went wrong.
	 * @return void
	 */
	private function fail( KnowledgeSource $source, string $message ): void {
		$source->status    = SourceStatus::Error;
		$source->lastError = mb_substr( $message, 0, 500 );

		$this->sources->save( $source );
	}
}
