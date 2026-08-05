<?php
/**
 * Live progress of an ingestion run.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Services;

/**
 * What an in-flight import has done so far.
 *
 * Written to the source's progress column so the admin screen can show
 * it while the job is still running in another process. That is the only
 * channel available: the job holds no connection to the browser, and a
 * customer watching a spinner with no number attached assumes it has
 * hung — usually at about forty seconds.
 */
final class IngestionProgress {

	/**
	 * Construct.
	 *
	 * @param int         $processed Documents handled.
	 * @param int         $total     Documents expected, or 0 when unknown.
	 * @param int         $indexed   Documents whose content changed and were chunked.
	 * @param int         $skipped   Documents unchanged since the last run.
	 * @param int         $failed    Documents that could not be read.
	 * @param int         $chunks    Chunks written.
	 * @param string      $stage     What is happening now.
	 * @param string|null $current   The document being handled.
	 */
	public function __construct(
		public int $processed = 0,
		public int $total = 0,
		public int $indexed = 0,
		public int $skipped = 0,
		public int $failed = 0,
		public int $chunks = 0,
		public string $stage = 'queued',
		public ?string $current = null,
	) {
	}

	/**
	 * Percentage complete, or null when the total is unknown.
	 *
	 * Null rather than a guess. A bar that reaches 90% and stays there
	 * because the estimate was low is worse than a count that simply
	 * climbs, which is what the UI shows instead.
	 *
	 * @return int|null
	 */
	public function percent(): ?int {
		if ( $this->total <= 0 ) {
			return null;
		}

		return (int) min( 100, round( ( $this->processed / $this->total ) * 100 ) );
	}

	/**
	 * As stored and as sent to the browser.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'processed' => $this->processed,
			'total'     => $this->total,
			'indexed'   => $this->indexed,
			'skipped'   => $this->skipped,
			'failed'    => $this->failed,
			'chunks'    => $this->chunks,
			'stage'     => $this->stage,
			'current'   => $this->current,
			'percent'   => $this->percent(),
		);
	}

	/**
	 * Rebuild from stored JSON.
	 *
	 * @param array<string, mixed> $data Stored value.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		$int = static fn ( string $key ): int =>
			isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ? (int) $data[ $key ] : 0;

		return new self(
			processed: $int( 'processed' ),
			total: $int( 'total' ),
			indexed: $int( 'indexed' ),
			skipped: $int( 'skipped' ),
			failed: $int( 'failed' ),
			chunks: $int( 'chunks' ),
			stage: isset( $data['stage'] ) && is_string( $data['stage'] ) ? $data['stage'] : 'queued',
			current: isset( $data['current'] ) && is_string( $data['current'] ) ? $data['current'] : null,
		);
	}
}
