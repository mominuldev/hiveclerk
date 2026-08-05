<?php
/**
 * Where a retrieval spent its time.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Knowledge;

/**
 * Stage-by-stage timings and counts for one search.
 *
 * Collected on every retrieval, not only in the playground. A slow answer
 * on a customer's site has four candidate causes — the embedding call,
 * the coarse scan, the exact pass, the keyword query — and without the
 * split the only available diagnosis is "retrieval is slow", which is not
 * one.
 */
final class RetrievalDiagnostics {

	/**
	 * Chunks the coarse pass considered.
	 *
	 * @var int
	 */
	public int $scanned = 0;

	/**
	 * Candidates it kept.
	 *
	 * @var int
	 */
	public int $candidates = 0;

	/**
	 * Rows the keyword search matched.
	 *
	 * @var int
	 */
	public int $keywordMatches = 0;

	/**
	 * Results returned.
	 *
	 * @var int
	 */
	public int $returned = 0;

	/**
	 * Results at or above the confidence threshold.
	 *
	 * @var int
	 */
	public int $confident = 0;

	/**
	 * Milliseconds spent embedding the query.
	 *
	 * @var float
	 */
	public float $embedMs = 0.0;

	/**
	 * Milliseconds spent in the coarse Hamming pass.
	 *
	 * @var float
	 */
	public float $stage1Ms = 0.0;

	/**
	 * Milliseconds spent in the exact cosine pass.
	 *
	 * @var float
	 */
	public float $stage2Ms = 0.0;

	/**
	 * Milliseconds spent in the keyword query.
	 *
	 * @var float
	 */
	public float $keywordMs = 0.0;

	/**
	 * Milliseconds spent fusing and loading text.
	 *
	 * @var float
	 */
	public float $fusionMs = 0.0;

	/**
	 * Milliseconds for the whole call.
	 *
	 * @var float
	 */
	public float $totalMs = 0.0;

	/**
	 * Which strategy the store used.
	 *
	 * @var string
	 */
	public string $strategy = 'two_stage';

	/**
	 * Where the binary matrix came from.
	 *
	 * @var string
	 */
	public string $matrixSource = 'database';

	/**
	 * Which popcount implementation ran.
	 *
	 * @var string
	 */
	public string $popcount = 'table';

	/**
	 * Whether the whole result came from the query cache.
	 *
	 * @var bool
	 */
	public bool $cached = false;

	/**
	 * Peak memory during the call, in bytes.
	 *
	 * @var int
	 */
	public int $peakBytes = 0;

	/**
	 * Anything that degraded the search rather than failing it.
	 *
	 * @var array<int, string>
	 */
	public array $notes = array();

	/**
	 * Record a note, once.
	 *
	 * @param string $note Human-readable observation.
	 * @return void
	 */
	public function note( string $note ): void {
		if ( ! in_array( $note, $this->notes, true ) ) {
			$this->notes[] = $note;
		}
	}

	/**
	 * Wire form for the playground.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'scanned'         => $this->scanned,
			'candidates'      => $this->candidates,
			'keyword_matches' => $this->keywordMatches,
			'returned'        => $this->returned,
			'confident'       => $this->confident,
			'embed_ms'        => round( $this->embedMs, 2 ),
			'stage1_ms'       => round( $this->stage1Ms, 2 ),
			'stage2_ms'       => round( $this->stage2Ms, 2 ),
			'keyword_ms'      => round( $this->keywordMs, 2 ),
			'fusion_ms'       => round( $this->fusionMs, 2 ),
			'total_ms'        => round( $this->totalMs, 2 ),
			'strategy'        => $this->strategy,
			'matrix_source'   => $this->matrixSource,
			'popcount'        => $this->popcount,
			'cached'          => $this->cached,
			'peak_mb'         => round( $this->peakBytes / 1048576, 1 ),
			'notes'           => $this->notes,
		);
	}
}
