<?php
/**
 * M1 retrieval benchmark.
 *
 * Measures the three numbers the M1 gate is defined by, at 1k, 10k and 50k
 * chunks: quantisation recall, search latency, and peak memory.
 *
 * Run with:  wp eval-file tools/retrieval-bench.php [sizes] [queries]
 *   wp eval-file tools/retrieval-bench.php 1000,10000,50000 50
 *
 * ## What this measures, and what it deliberately does not
 *
 * The corpus is synthetic: deterministic pseudo-random vectors written
 * straight into the embeddings table, with no provider call anywhere. That
 * is a feature, not a shortcut. The M1 latency and memory budgets are
 * properties of *our* implementation — the matrix scan, the popcount, the
 * exact re-rank, the object cache — and mixing in a third party's network
 * latency and a specific embedding model's quality would measure their
 * work and report it as ours.
 *
 * The recall figure here is likewise *quantisation* recall: how often the
 * two-stage pipeline returns the same top-k as an exact brute-force scan
 * of the same vectors. It isolates what binary quantisation costs. It is
 * not the same number as end-to-end retrieval recall against real
 * questions, which depends on the embedding model and the customer's
 * content — that is what tools/retrieval-eval.php measures, and it needs a
 * provider key and indexed content.
 *
 * No declare(strict_types=1): wp eval-file evals this file's contents, and
 * a strict_types declaration is only legal as the first statement.
 *
 * @package Hiveclerk
 */

use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Knowledge\Embedding;
use Hiveclerk\Domain\Knowledge\EmbeddingRepositoryInterface;
use Hiveclerk\Domain\Knowledge\RetrievalOptions;
use Hiveclerk\Domain\Knowledge\StoredEmbedding;
use Hiveclerk\Domain\Knowledge\VectorStoreInterface;
use Hiveclerk\Modules\KnowledgeBase\Vector\BinaryQuantiser;
use Hiveclerk\Modules\KnowledgeBase\Vector\CosineCalculator;
use Hiveclerk\Modules\KnowledgeBase\Vector\VectorCodec;
use Hiveclerk\Plugin;

/*
 * Budgets from Deliverable 6 §14 and the Sprint 4 exit criteria.
 */
const HVC_BENCH_RECALL_FLOOR = 0.90;
const HVC_BENCH_P95_MS       = 300.0;
const HVC_BENCH_PEAK_MB      = 96.0;

$hvc_args    = isset( $args ) && is_array( $args ) ? $args : array();
$hvc_sizes   = array_map( 'intval', explode( ',', (string) ( $hvc_args[0] ?? '1000,10000,50000' ) ) );
$hvc_queries = max( 5, (int) ( $hvc_args[1] ?? 50 ) );
$hvc_dims    = 1536;
$hvc_source  = 999000001;

/*
 * Recall is measured over far fewer queries than latency, and the reason
 * is arithmetic rather than laziness. Establishing ground truth means an
 * exact cosine against every vector in the corpus — at fifty thousand
 * chunks that is seventy-seven million float operations per query, in
 * PHP. Fifty of those would take longer than the sprint. Latency is cheap
 * to sample, so it is sampled properly; recall is expensive, so it is
 * sampled enough to be meaningful and no more.
 */
$hvc_recallQueries = max( 3, min( 10, $hvc_queries ) );

/**
 * How many recall probes to run at a given corpus size.
 *
 * Ground truth costs O(corpus) per probe, so a fixed count makes the
 * 50,000-chunk run twenty-five times slower than the 1,000-chunk one for
 * no extra confidence. The budget is held roughly constant instead.
 *
 * @param int $size    Corpus size.
 * @param int $ceiling Maximum probes.
 * @return int
 */
function hvc_bench_probes( $size, $ceiling ) {
	return max( 3, min( $ceiling, (int) ceil( 10000000 / max( 1, $size ) ) ) );
}

$hvc_container  = Plugin::instance()->container();
$hvc_store      = $hvc_container->get( VectorStoreInterface::class );
$hvc_embeddings = $hvc_container->get( EmbeddingRepositoryInterface::class );

/**
 * A deterministic pseudo-random vector.
 *
 * Seeded arithmetic rather than mt_rand(): a benchmark whose corpus
 * changes between runs cannot show a regression, only noise.
 *
 * @param int $dimensions Width.
 * @param int $seed       Seed.
 * @return array<int, float>
 */
function hvc_bench_vector( $dimensions, $seed ) {
	$vector = array();
	$state  = ( $seed * 2654435761 ) % 2147483647;

	for ( $i = 0; $i < $dimensions; $i++ ) {
		$state    = ( $state * 1103515245 + 12345 ) % 2147483647;
		$vector[] = ( $state / 2147483647 ) - 0.5;
	}

	return $vector;
}

/**
 * A vector drawn from a clustered corpus.
 *
 * Uniform random vectors are the wrong model for this measurement, and
 * getting that wrong makes the benchmark lie in the pessimistic
 * direction. In 1,536 dimensions independent random vectors are all
 * near-orthogonal: every pairwise cosine sits within about 0.026 of zero,
 * so the "top 5" differ from the 500th by the third decimal place. Asking
 * a one-bit-per-dimension approximation to resolve that is asking it to
 * do something no real query needs, and it fails — correctly.
 *
 * Real embedding spaces are not like that. A company's content forms
 * topical clusters, and a question lands near one of them: the relevant
 * chunks score 0.5 to 0.9 while the rest of the corpus sits near zero.
 * That separation is exactly what the coarse pass exploits, so the corpus
 * is generated with it. The uniform case is still measured, separately
 * and labelled as the adversarial floor.
 *
 * @param int   $dimensions Width.
 * @param int   $seed       Seed for this vector's own noise.
 * @param int   $cluster    Cluster index, or -1 for uniform.
 * @param float $spread     How far from the centroid, 0 to 1.
 * @return array<int, float>
 */
function hvc_bench_clustered( $dimensions, $seed, $cluster, $spread = 0.55 ) {
	$noise = hvc_bench_vector( $dimensions, $seed );

	if ( $cluster < 0 ) {
		return $noise;
	}

	$centroid = hvc_bench_vector( $dimensions, 7000000 + $cluster );
	$vector   = array();

	foreach ( $centroid as $index => $component ) {
		$vector[] = $component * ( 1 - $spread ) + $noise[ $index ] * $spread;
	}

	return $vector;
}

/**
 * Percentile of a sorted-able list.
 *
 * @param array<int, float> $values     Samples.
 * @param float             $percentile 0 to 1.
 * @return float
 */
function hvc_bench_percentile( $values, $percentile ) {
	if ( array() === $values ) {
		return 0.0;
	}

	sort( $values );

	$index = (int) ceil( $percentile * count( $values ) ) - 1;

	return $values[ max( 0, min( count( $values ) - 1, $index ) ) ];
}

echo "\nHiveclerk — M1 retrieval benchmark\n";
echo str_repeat( '=', 78 ) . "\n";
echo sprintf(
	"Dimensions %d · %d queries per size · popcount: %s · object cache: %s\n\n",
	$hvc_dims,
	$hvc_queries,
	BinaryQuantiser::implementation(),
	wp_using_ext_object_cache() ? 'persistent' : 'NOT persistent (transient fallback)'
);

$hvc_failures = array();

/*
 * Two corpus profiles, both measured every run.
 *
 * "clustered" models what a customer's content actually looks like in
 * embedding space and is the figure the M1 gate is read against.
 * "uniform" is the adversarial floor — a corpus with no topical structure
 * at all, where the top result and the five-hundredth differ in the third
 * decimal. Reporting only the first would flatter the design; reporting
 * only the second would condemn it for failing a case that does not
 * occur. Both, labelled, is the honest answer.
 */
$hvc_profiles = array(
	'clustered' => true,
	'uniform'   => false,
);

foreach ( $hvc_sizes as $hvc_size ) {
	if ( $hvc_size <= 0 ) {
		continue;
	}

	echo sprintf( "── %s chunks ", number_format( $hvc_size ) ) . str_repeat( '─', 55 ) . "\n";

	// Roughly fifty chunks per topic, which is about what one section of a
	// medium site's documentation produces.
	$hvc_clusters = max( 20, intdiv( $hvc_size, 50 ) );

foreach ( $hvc_profiles as $hvc_profile => $hvc_isClustered ) {

	// Fresh table state per profile. Leaving the previous run's rows behind
	// would make each measurement the sum of everything before it.
	$hvc_embeddings->deleteForSource( $hvc_source );
	$hvc_store->invalidate( array( $hvc_source ) );

	$hvc_writeStart = microtime( true );

	for ( $hvc_offset = 0; $hvc_offset < $hvc_size; $hvc_offset += 500 ) {
		$hvc_batch = array();

		for ( $hvc_i = $hvc_offset; $hvc_i < min( $hvc_size, $hvc_offset + 500 ); $hvc_i++ ) {
			$hvc_chunkId = 900000000 + $hvc_i;
			$hvc_vector  = hvc_bench_clustered(
				$hvc_dims,
				$hvc_i + 1,
				$hvc_isClustered ? $hvc_i % $hvc_clusters : -1
			);

			$hvc_batch[] = new StoredEmbedding(
				chunkId: $hvc_chunkId,
				sourceId: $hvc_source,
				provider: 'bench',
				model: 'synthetic',
				dimensions: $hvc_dims,
				f32: VectorCodec::pack( $hvc_vector ),
				bits: BinaryQuantiser::quantise( $hvc_vector ),
				norm: CosineCalculator::norm( $hvc_vector )
			);
		}

		$hvc_embeddings->saveMany( $hvc_batch );
	}

	printf(
		"   %-10s corpus seeded in %.1fs%s\n",
		$hvc_profile,
		microtime( true ) - $hvc_writeStart,
		$hvc_isClustered ? sprintf( ' · %d topics', $hvc_clusters ) : ' · no topical structure'
	);

	// Peak is sampled from here, so the seeding loop's own allocations do
	// not get attributed to search. Seeding runs in a job on a real site;
	// the budget being tested is the one a visitor's request has to fit in.
	$hvc_peakBefore = memory_get_peak_usage( true );

	$hvc_latencies = array();
	$hvc_stage1    = array();
	$hvc_stage2    = array();
	$hvc_recall    = array();
	$hvc_margins   = array();
	$hvc_source_of = 'unknown';

	for ( $hvc_q = 0; $hvc_q < $hvc_queries; $hvc_q++ ) {
		// A query lands inside a topic, not between them — which is what a
		// real question does, and what makes the coarse pass's job possible.
		$hvc_queryVector = hvc_bench_clustered(
			$hvc_dims,
			5000000 + $hvc_q,
			$hvc_isClustered ? $hvc_q % $hvc_clusters : -1
		);

		$hvc_query = new Embedding( $hvc_queryVector, 'bench', 'synthetic' );
		$hvc_options = RetrievalOptions::of( topK: 5 );
		$hvc_diag    = new Hiveclerk\Domain\Knowledge\RetrievalDiagnostics();

		$hvc_started = microtime( true );
		$hvc_results = $hvc_store->search( $hvc_query, array( $hvc_source ), 5, $hvc_options, $hvc_diag );
		$hvc_elapsed = ( microtime( true ) - $hvc_started ) * 1000;

		$hvc_latencies[] = $hvc_elapsed;
		$hvc_stage1[]    = $hvc_diag->stage1Ms;
		$hvc_stage2[]    = $hvc_diag->stage2Ms;
		$hvc_source_of   = $hvc_diag->matrixSource;

		if ( $hvc_q >= hvc_bench_probes( $hvc_size, $hvc_recallQueries ) ) {
			continue;
		}

		/*
		 * Ground truth: exact cosine against every stored vector, read back
		 * in batches so the whole corpus is never resident. Reading through
		 * the storage layer would normally be circular — a corrupted write
		 * would be compared against itself and report perfect recall — so
		 * the integrity check below verifies the stored bytes against
		 * regenerated ones separately.
		 */
		$hvc_exact     = array();
		$hvc_queryNorm = CosineCalculator::norm( $hvc_queryVector );

		for ( $hvc_offset = 0; $hvc_offset < $hvc_size; $hvc_offset += 1000 ) {
			$hvc_ids = range(
				900000000 + $hvc_offset,
				900000000 + min( $hvc_size, $hvc_offset + 1000 ) - 1
			);

			foreach ( $hvc_embeddings->exact( $hvc_ids, 'bench', 'synthetic' ) as $hvc_id => $hvc_row ) {
				$hvc_exact[ $hvc_id ] = CosineCalculator::score(
					$hvc_queryVector,
					$hvc_queryNorm,
					$hvc_row->f32,
					$hvc_row->norm
				);
			}
		}

		arsort( $hvc_exact );

		$hvc_scores = array_values( $hvc_exact );
		$hvc_truth  = array_slice( array_keys( $hvc_exact ), 0, 5 );
		$hvc_got    = array_map( static fn ( $r ) => $r->chunkId, $hvc_results );
		$hvc_hits   = count( array_intersect( $hvc_truth, $hvc_got ) );

		$hvc_recall[] = $hvc_hits / 5;

		// The gap between the best match and the corpus median. This is
		// what recall actually depends on, and printing it is what stops a
		// low recall figure from being mistaken for a broken implementation
		// when it is really a corpus with nothing to find.
		$hvc_margins[] = ( $hvc_scores[0] ?? 0.0 ) - ( $hvc_scores[ intdiv( count( $hvc_scores ), 2 ) ] ?? 0.0 );

		unset( $hvc_exact, $hvc_scores );
	}

	/*
	 * Storage integrity, spot-checked. Twenty rows read back and compared
	 * component by component against vectors regenerated from their seeds.
	 * This is what stops the recall figure above from being circular: if
	 * pack/unpack or the BLOB round trip ever mangled a vector, recall
	 * measured against the mangled copy would still read 1.000.
	 */
	$hvc_drift = 0.0;

	foreach ( range( 0, 19 ) as $hvc_i ) {
		if ( $hvc_i >= $hvc_size ) {
			break;
		}

		$hvc_stored = $hvc_embeddings->exact( array( 900000000 + $hvc_i ), 'bench', 'synthetic' );
		$hvc_row    = $hvc_stored[ 900000000 + $hvc_i ] ?? null;

		if ( null === $hvc_row ) {
			$hvc_drift = INF;

			break;
		}

		$hvc_expected = hvc_bench_clustered(
			$hvc_dims,
			$hvc_i + 1,
			$hvc_isClustered ? $hvc_i % $hvc_clusters : -1
		);
		$hvc_actual   = VectorCodec::unpack( $hvc_row->f32 );

		foreach ( $hvc_expected as $hvc_d => $hvc_component ) {
			$hvc_drift = max( $hvc_drift, abs( $hvc_component - ( $hvc_actual[ $hvc_d ] ?? 0.0 ) ) );
		}
	}

	/*
	 * The number production actually lives on, and the one a single-process
	 * benchmark otherwise never measures.
	 *
	 * Every query above ran in one PHP process, so after the first one the
	 * matrix came from MatrixCache's in-request memo — a path no real
	 * request takes twice. What a real second request does is read the
	 * matrix back out of the object cache or the transient it was written
	 * to. A fresh cache instance forces exactly that, so the steady-state
	 * cost is measured rather than assumed.
	 */
	$hvc_freshCache = new Hiveclerk\Modules\KnowledgeBase\Vector\MatrixCache();
	$hvc_freshStore = new Hiveclerk\Modules\KnowledgeBase\Vector\MysqlBlobVectorStore(
		$hvc_embeddings,
		$hvc_freshCache
	);

	$hvc_crossDiag  = new Hiveclerk\Domain\Knowledge\RetrievalDiagnostics();
	$hvc_crossStart = microtime( true );

	$hvc_freshStore->search(
		new Embedding( hvc_bench_clustered( $hvc_dims, 6000001, $hvc_isClustered ? 0 : -1 ), 'bench', 'synthetic' ),
		array( $hvc_source ),
		5,
		RetrievalOptions::of( topK: 5 ),
		$hvc_crossDiag
	);

	$hvc_crossMs = ( microtime( true ) - $hvc_crossStart ) * 1000;

	$hvc_peakMb  = ( memory_get_peak_usage( true ) - $hvc_peakBefore ) / 1048576;
	$hvc_totalMb = memory_get_peak_usage( true ) / 1048576;

	$hvc_meanRecall = array_sum( $hvc_recall ) / count( $hvc_recall );

	/*
	 * The first query of a request pays for building the matrix; every
	 * one after it reads the memo. Reporting them together turns one cold
	 * build into a p95 that describes no real request, so they are split.
	 *
	 * Which figure the M1 gate should be read against depends on the host.
	 * With a persistent object cache the build is paid once per day and
	 * warm is the honest number. Without one — and above the transient
	 * ceiling, where the matrix cannot be cached at all — every request is
	 * cold, and cold is the honest number. Both are printed so the reader
	 * does not have to take the benchmark's word for which applies.
	 */
	$hvc_cold   = $hvc_latencies[0] ?? 0.0;
	$hvc_warm   = array_slice( $hvc_latencies, 1 );
	$hvc_p95    = hvc_bench_percentile( array() === $hvc_warm ? $hvc_latencies : $hvc_warm, 0.95 );
	$hvc_median = hvc_bench_percentile( array() === $hvc_warm ? $hvc_latencies : $hvc_warm, 0.50 );

	$hvc_margin = array() === $hvc_margins ? 0.0 : array_sum( $hvc_margins ) / count( $hvc_margins );
	$hvc_isGate = $hvc_isClustered;

	// Latency is only an M1 gate at 10,000 chunks. Elsewhere the number is
	// still worth reading and stamping it FAIL would be a lie about what
	// was promised.
	$hvc_gatesBudget = $hvc_isGate && 10000 === $hvc_size;
	$hvc_verdict      = static fn ( $ok, $gated = null ) => ( $gated ?? $hvc_isGate )
		? ( $ok ? 'PASS' : 'FAIL' )
		: ( $ok ? 'ok' : 'over budget (not an M1 gate)' );

	printf(
		"     recall@5    %.3f   %s (floor %.2f, %d queries, best-vs-median cosine margin %.3f)\n",
		$hvc_meanRecall,
		$hvc_verdict( $hvc_meanRecall >= HVC_BENCH_RECALL_FLOOR ),
		HVC_BENCH_RECALL_FLOOR,
		count( $hvc_recall ),
		$hvc_margin
	);
	printf(
		"     latency     warm p95 %.1f ms %s (budget %.0f ms) · warm median %.1f ms · cold first query %.1f ms\n",
		$hvc_p95,
		$hvc_verdict( $hvc_p95 <= HVC_BENCH_P95_MS, $hvc_gatesBudget ),
		HVC_BENCH_P95_MS,
		$hvc_median,
		$hvc_cold
	);
	printf(
		"                 stage 1 %.1f ms median · stage 2 %.1f ms median · matrix from %s\n",
		hvc_bench_percentile( $hvc_stage1, 0.50 ),
		hvc_bench_percentile( $hvc_stage2, 0.50 ),
		$hvc_source_of
	);
	printf(
		"     next request %.1f ms %s · matrix from %s%s\n",
		$hvc_crossMs,
		$hvc_verdict( $hvc_crossMs <= HVC_BENCH_P95_MS, $hvc_gatesBudget ),
		$hvc_crossDiag->matrixSource,
		'database' === $hvc_crossDiag->matrixSource
			? ' ← NOT CACHED: every request rebuilds it'
			: ''
	);
	printf(
		"     memory      %.1f MB peak (%.1f MB from search) %s · float32 drift %.2e %s\n\n",
		$hvc_totalMb,
		$hvc_peakMb,
		$hvc_verdict( $hvc_totalMb <= HVC_BENCH_PEAK_MB ),
		$hvc_drift,
		$hvc_drift < 1e-6 ? 'ok' : 'FAIL — stored vectors do not match what was written'
	);

	if ( $hvc_drift >= 1e-6 ) {
		$hvc_failures[] = sprintf( '%d chunks: stored vectors drifted by %.2e from what was written', $hvc_size, $hvc_drift );
	}

	if ( ! $hvc_isGate ) {
		// The uniform corpus is reported for context, never gated on. It
		// has no topical structure, so there is no signal for quantisation
		// to preserve and a low figure says nothing about the code.
		continue;
	}

	if ( $hvc_meanRecall < HVC_BENCH_RECALL_FLOOR ) {
		$hvc_failures[] = sprintf( '%d chunks: recall@5 %.3f', $hvc_size, $hvc_meanRecall );
	}

	if ( $hvc_gatesBudget && $hvc_p95 > HVC_BENCH_P95_MS ) {
		$hvc_failures[] = sprintf( '10k chunks: warm p95 %.1f ms over the %.0f ms budget', $hvc_p95, HVC_BENCH_P95_MS );
	}

	// The steady state on a real site is the cross-request figure, not the
	// warm in-process one. Gating on it is what stops a benchmark that
	// measures its own memo from certifying a product that rebuilds the
	// matrix on every visitor message.
	if ( $hvc_gatesBudget && $hvc_crossMs > HVC_BENCH_P95_MS ) {
		$hvc_failures[] = sprintf(
			'10k chunks: %.1f ms on a fresh request (matrix from %s) over the %.0f ms budget',
			$hvc_crossMs,
			$hvc_crossDiag->matrixSource,
			HVC_BENCH_P95_MS
		);
	}

	// Memory is gated at the same scale as latency, for the same reason:
	// the M1 exit criteria are defined at 10,000 chunks. The 50,000-chunk
	// figure is printed above and belongs in the release notes, but the
	// architecture answers that tier with per-source partitioned matrices,
	// which is Sprint 9 work and not what this gate is measuring.
	if ( $hvc_gatesBudget && $hvc_totalMb > HVC_BENCH_PEAK_MB ) {
		$hvc_failures[] = sprintf( '%d chunks: peak %.1f MB over the %.0f MB budget', $hvc_size, $hvc_totalMb, HVC_BENCH_PEAK_MB );
	}
}
}

$hvc_embeddings->deleteForSource( $hvc_source );
$hvc_store->invalidate( array( $hvc_source ) );

echo str_repeat( '=', 78 ) . "\n";

if ( array() === $hvc_failures ) {
	echo "M1 budgets met on this machine.\n\n";
	echo "Note: this is quantisation recall on synthetic vectors, not end-to-end\n";
	echo "recall against real questions. Run tools/retrieval-eval.php for that.\n";

	return;
}

echo "M1 BUDGETS MISSED:\n";

foreach ( $hvc_failures as $hvc_failure ) {
	echo '  - ' . $hvc_failure . "\n";
}

echo "\nDeliverable 13 §5 describes the ladder to run before Sprint 5 begins.\n";

exit( 1 );
