<?php
/**
 * Drives content into the database as chunks.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Services;

use Hiveclerk\Domain\Knowledge\Document;
use Hiveclerk\Domain\Knowledge\DocumentRepositoryInterface;
use Hiveclerk\Domain\Knowledge\ChunkQuotaInterface;
use Hiveclerk\Domain\Knowledge\ChunkRepositoryInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSource;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
use Hiveclerk\Domain\Knowledge\SourceStatus;
use Hiveclerk\Modules\KnowledgeBase\Extractors\ExtractedDocument;
use Hiveclerk\Modules\KnowledgeBase\Extractors\ExtractorRegistry;
use Hiveclerk\Modules\KnowledgeBase\Text\ChunkOptions;
use Throwable;

/**
 * Runs one source from extraction to stored chunks (FR-KB-08).
 *
 * The service owns three things extractors deliberately do not: how much
 * work is allowed, when to stop, and what the operator is told while it
 * happens.
 *
 * ## Unchanged documents are not re-chunked
 *
 * Every document is hashed and compared to what is stored. A re-index of
 * an unchanged site does almost no work, which matters because the next
 * stage after this one is embedding, and embedding is billed per token.
 * Re-embedding a thousand unchanged pages costs the customer real money
 * for an identical result.
 *
 * ## Removals are propagated
 *
 * Documents present last time and absent now are deleted. A page taken
 * down for legal reasons that the clerk keeps quoting is worse than one
 * that was never indexed, because the customer believes it is gone.
 *
 * ## Failure is per document
 *
 * One unreadable PDF in a folder of forty must not fail the other
 * thirty-nine. Failures are counted, the last one is reported, and the
 * run continues.
 */
final class IngestionService {

	/**
	 * Documents between progress writes.
	 *
	 * Every document would be one UPDATE per document, which on a large
	 * import is more writes than the import itself. Fifteen is often
	 * enough that the number on screen still moves.
	 */
	private const PROGRESS_EVERY = 15;

	/**
	 * Construct.
	 *
	 * @param ExtractorRegistry                  $extractors Extractors.
	 * @param ChunkerService                     $chunker    Chunker.
	 * @param KnowledgeSourceRepositoryInterface $sources    Sources.
	 * @param DocumentRepositoryInterface        $documents  Documents.
	 * @param ChunkRepositoryInterface           $chunks     Chunks.
	 * @param ChunkQuotaInterface                $quota      How much may still be indexed.
	 */
	public function __construct(
		private readonly ExtractorRegistry $extractors,
		private readonly ChunkerService $chunker,
		private readonly KnowledgeSourceRepositoryInterface $sources,
		private readonly DocumentRepositoryInterface $documents,
		private readonly ChunkRepositoryInterface $chunks,
		private readonly ChunkQuotaInterface $quota,
	) {
	}

	/**
	 * Index a source.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return IngestionProgress
	 */
	public function run( KnowledgeSource $source ): IngestionProgress {
		$progress = new IngestionProgress( stage: 'starting' );

		if ( null === $source->id ) {
			return $progress;
		}

		$sourceId  = $source->id;
		$extractor = $this->extractors->for( $source->type );

		if ( null === $extractor ) {
			return $this->fail( $source, $progress, sprintf( 'No extractor is registered for %s sources.', $source->type->value ) );
		}

		if ( ! $extractor->isAvailable() ) {
			return $this->fail( $source, $progress, $extractor->unavailableReason() );
		}

		$this->clearCancellation( $sourceId );

		$source->status    = SourceStatus::Processing;
		$source->lastError = null;
		$this->sources->save( $source );

		$progress->total = $extractor->estimate( $source ) ?? 0;
		$progress->stage = 'extracting';
		$this->publish( $source, $progress );

		$options  = ChunkOptions::fromConfig( $source->config );
		$seen     = array();
		$known    = $this->documents->externalIds( $sourceId );
		$headroom = $this->headroomFor( $source );

		if ( null !== $headroom && $headroom <= 0 ) {
			return $this->fail(
				$source,
				$progress,
				__( 'This licence has no indexed chunks left. Everything already indexed keeps working.', 'hiveclerk' )
			);
		}

		try {
			foreach ( $extractor->extract( $source ) as $extracted ) {
				if ( $this->isCancelled( $sourceId ) ) {
					$progress->stage = 'cancelled';

					return $this->finish( $source, $progress, SourceStatus::Ready );
				}

				$seen[ $extracted->externalId ] = true;

				$this->ingestOne( $source, $extracted, $options, $progress );

				++$progress->processed;

				/*
				 * Checked between documents, not per chunk. Stopping
				 * inside a document would store half an answer, and half
				 * an answer retrieved with confidence is worse than no
				 * answer at all — so the run overshoots by at most one
				 * document and then stops cleanly.
				 *
				 * Documents already written stay written. The source is
				 * left Ready with an explanation rather than Failed:
				 * partial knowledge is still knowledge, and marking it
				 * failed would hide it from every clerk that reads it.
				 */
				if ( null !== $headroom && $progress->chunks >= $headroom ) {
					$progress->stage = 'quota_reached';

					$source->lastError = sprintf(
						/* translators: %s: chunk allowance, already formatted. */
						__( 'Indexing stopped at this licence\'s %s-chunk allowance. Everything indexed so far is live.', 'hiveclerk' ),
						number_format_i18n( $headroom )
					);

					$this->prune( $known, $seen );

					return $this->finish( $source, $progress, SourceStatus::Ready );
				}

				if ( 0 === $progress->processed % self::PROGRESS_EVERY ) {
					$this->publish( $source, $progress );
				}
			}
		} catch ( Throwable $e ) {
			// A throw from the generator itself is the whole source
			// failing — an unreachable site, a rejected credential — not
			// one bad document. Those are caught inside ingestOne().
			return $this->fail( $source, $progress, $e->getMessage() );
		}

		$progress->stage = 'pruning';
		$this->publish( $source, $progress );

		$this->prune( $known, $seen );

		return $this->finish( $source, $progress, SourceStatus::Ready );
	}

	/**
	 * How many chunks this run may create.
	 *
	 * The source's own existing chunks are added back before the check,
	 * because a re-index replaces them rather than adding to them.
	 * Without that, re-indexing a source that already fills the allowance
	 * would refuse to run at all, and the customer would watch their
	 * knowledge base go stale with no way to refresh it.
	 *
	 * @param KnowledgeSource $source Source being indexed.
	 * @return int|null Null means no limit.
	 */
	private function headroomFor( KnowledgeSource $source ): ?int {
		$elsewhere = max( 0, $this->sources->totalChunks() - $source->chunkCount );

		return $this->quota->remaining( $elsewhere );
	}

	/**
	 * Ask a running import to stop.
	 *
	 * A flag rather than a signal, because the job is in another process
	 * and there is nothing to signal. It is read between documents, so
	 * cancelling a crawl stops it after the page in flight rather than
	 * immediately — which is also what makes the stop point consistent.
	 *
	 * @param int $sourceId Source.
	 * @return void
	 */
	public function cancel( int $sourceId ): void {
		set_transient( $this->cancelKey( $sourceId ), 1, HOUR_IN_SECONDS );
	}

	/**
	 * Whether a stop has been requested.
	 *
	 * @param int $sourceId Source.
	 * @return bool
	 */
	public function isCancelled( int $sourceId ): bool {
		return false !== get_transient( $this->cancelKey( $sourceId ) );
	}

	/**
	 * Remove everything a source has indexed.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return void
	 */
	public function purge( KnowledgeSource $source ): void {
		if ( null === $source->id ) {
			return;
		}

		$this->documents->deleteForSource( $source->id );

		$source->documentCount = 0;
		$source->chunkCount    = 0;
		$source->tokenCount    = 0;
		$source->status        = SourceStatus::Pending;

		$this->sources->save( $source );
	}

	/**
	 * Store one extracted document and its chunks.
	 *
	 * @param KnowledgeSource   $source    Source.
	 * @param ExtractedDocument $extracted Extracted document.
	 * @param ChunkOptions      $options   Chunking parameters.
	 * @param IngestionProgress $progress  Progress, mutated.
	 * @return void
	 */
	private function ingestOne(
		KnowledgeSource $source,
		ExtractedDocument $extracted,
		ChunkOptions $options,
		IngestionProgress $progress
	): void {
		$sourceId = (int) $source->id;

		$progress->current = '' !== $extracted->title ? $extracted->title : $extracted->url;

		try {
			if ( $extracted->isEmpty() ) {
				// Not a failure. A gallery page legitimately has no prose.
				// It is counted as skipped so the totals still add up.
				++$progress->skipped;

				return;
			}

			$content  = $extracted->text->text;
			$existing = $this->documents->findByExternalId( $sourceId, $extracted->externalId );

			if ( null !== $existing && ! $existing->hasChanged( $content ) ) {
				++$progress->skipped;
				$progress->chunks += $existing->chunkCount;

				return;
			}

			$document = $existing ?? new Document(
				id: null,
				sourceId: $sourceId,
				externalId: $extracted->externalId
			);

			$document->url         = $extracted->url;
			$document->title       = $extracted->title;
			$document->content     = $content;
			$document->contentHash = Document::hash( $content );
			$document->language    = $extracted->language;
			$document->metadata    = $extracted->metadata;
			$document->status      = 'indexed';

			$document = $this->documents->save( $document );

			if ( null === $document->id ) {
				++$progress->failed;

				return;
			}

			$chunks = $this->chunker->chunk( $extracted->text, $document->id, $sourceId, $options );

			$this->chunks->replaceForDocument( $document->id, $chunks );

			// Counts are written back after the chunks exist, so a crash
			// between the two understates the document rather than
			// claiming chunks that were never stored.
			$document->chunkCount = count( $chunks );
			$document->tokenCount = array_sum( array_map( static fn ( $chunk ): int => $chunk->tokenCount, $chunks ) );

			$this->documents->save( $document );

			++$progress->indexed;
			$progress->chunks += count( $chunks );
		} catch ( Throwable $e ) {
			// One document, one failure. A folder of forty PDFs must not
			// be lost to the one that is password-protected.
			++$progress->failed;

			/**
			 * Fires when a single document cannot be ingested.
			 *
			 * @param ExtractedDocument $extracted The document.
			 * @param Throwable         $e         The failure.
			 * @param KnowledgeSource   $source    Owning source.
			 */
			do_action( 'hiveclerk/knowledge/document_failed', $extracted, $e, $source );
		}
	}

	/**
	 * Delete documents the source no longer contains.
	 *
	 * @param array<string, int>  $known External id to storage id, before the run.
	 * @param array<string, bool> $seen  External ids encountered during it.
	 * @return void
	 */
	private function prune( array $known, array $seen ): void {
		foreach ( $known as $externalId => $documentId ) {
			if ( ! isset( $seen[ $externalId ] ) ) {
				$this->documents->delete( $documentId );
			}
		}
	}

	/**
	 * Record a run that could not proceed.
	 *
	 * @param KnowledgeSource   $source   Source.
	 * @param IngestionProgress $progress Progress.
	 * @param string            $message  What went wrong.
	 * @return IngestionProgress
	 */
	private function fail( KnowledgeSource $source, IngestionProgress $progress, string $message ): IngestionProgress {
		$progress->stage = 'error';

		$source->status = SourceStatus::Error;

		// Truncated because it reaches a TEXT column and, more to the
		// point, a UI panel. A stack trace pasted into the error field
		// tells the operator nothing and hides the first line, which is
		// the part that says what happened.
		$source->lastError = mb_substr( $message, 0, 500 );

		$this->publish( $source, $progress );

		return $progress;
	}

	/**
	 * Record a completed run.
	 *
	 * @param KnowledgeSource   $source   Source.
	 * @param IngestionProgress $progress Progress.
	 * @param SourceStatus      $status   Final status.
	 * @return IngestionProgress
	 */
	private function finish( KnowledgeSource $source, IngestionProgress $progress, SourceStatus $status ): IngestionProgress {
		$sourceId = (int) $source->id;

		if ( 'cancelled' !== $progress->stage ) {
			$progress->stage = 'done';
		}

		// Counted from storage rather than from the run's own tallies.
		// The tallies exclude documents skipped as unchanged, so a second
		// import of an unchanged site would otherwise report zero chunks
		// and the source would look empty.
		$source->documentCount = $this->documents->countForSource( $sourceId );
		$source->chunkCount    = $this->chunks->countForSource( $sourceId );
		$source->tokenCount    = $this->chunks->tokensForSource( $sourceId );
		$source->status        = $status;
		$source->lastSyncedAt  = gmdate( 'Y-m-d H:i:s' );
		$source->lastError     = $progress->failed > 0
			? sprintf( '%d document(s) could not be read and were skipped.', $progress->failed )
			: null;

		$this->publish( $source, $progress );
		$this->clearCancellation( $sourceId );

		/**
		 * Fires when a source finishes indexing.
		 *
		 * @param KnowledgeSource   $source   The source.
		 * @param IngestionProgress $progress Final counts.
		 */
		do_action( 'hiveclerk/knowledge/indexed', $source, $progress );

		return $progress;
	}

	/**
	 * Write progress where the admin screen can read it.
	 *
	 * @param KnowledgeSource   $source   Source.
	 * @param IngestionProgress $progress Progress.
	 * @return void
	 */
	private function publish( KnowledgeSource $source, IngestionProgress $progress ): void {
		$source->progress = $progress->toArray();

		$this->sources->save( $source );
	}

	/**
	 * Clear the stop flag.
	 *
	 * @param int $sourceId Source.
	 * @return void
	 */
	private function clearCancellation( int $sourceId ): void {
		delete_transient( $this->cancelKey( $sourceId ) );
	}

	/**
	 * Transient name for a source's stop flag.
	 *
	 * @param int $sourceId Source.
	 * @return string
	 */
	private function cancelKey( int $sourceId ): string {
		return 'hiveclerk_cancel_' . $sourceId;
	}
}
