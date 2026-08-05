<?php
/**
 * End-to-end retrieval evaluation.
 *
 * Measures recall@k and latency for the whole pipeline — real embeddings,
 * real content, real fusion — against a question set.
 *
 * Run with:  wp eval-file tools/retrieval-eval.php [questions|probe] [k] [limit]
 *   wp eval-file tools/retrieval-eval.php probe 5 200
 *   wp eval-file tools/retrieval-eval.php eval/questions.json 5
 *
 * ## Two modes, and why both exist
 *
 * **probe** derives its own question set from the indexed corpus: it
 * takes the most distinctive sentence of each of N chunks, uses it as a
 * query, and asks whether the chunk it came from comes back. This needs no
 * hand-authored data, so it can run on any customer's site — but it is
 * *optimistic*, and knowing by how much matters. A probe query shares
 * vocabulary with its target, while a real visitor writes "can I send this
 * back" about a page that says "returns are accepted within 30 days". Read
 * the probe figure as an upper bound and a regression detector, not as the
 * number a customer will experience.
 *
 * **A question file** is the honest measurement. JSON, one object per
 * question:
 *
 *   [ { "question": "Do you ship to Germany?", "document_ids": [42] },
 *     { "question": "What is the warranty on zips?", "chunk_ids": [9812] } ]
 *
 * Either key works; a question is a hit when any of its expected ids
 * appears in the top k.
 *
 * Both modes spend the customer's money — every question is an embedding
 * call. The cost is reported at the end rather than left to be discovered
 * on a provider invoice.
 *
 * No declare(strict_types=1): wp eval-file evals this file's contents, and
 * a strict_types declaration is only legal as the first statement.
 *
 * @package Hiveclerk
 */

use Hiveclerk\Domain\Knowledge\ChunkRepositoryInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
use Hiveclerk\Domain\Knowledge\RetrievalOptions;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Modules\KnowledgeBase\Services\EmbeddingService;
use Hiveclerk\Modules\KnowledgeBase\Services\RetrievalService;
use Hiveclerk\Plugin;

const HVC_EVAL_RECALL_FLOOR = 0.90;

$hvc_args      = isset( $args ) && is_array( $args ) ? $args : array();
$hvc_mode      = (string) ( $hvc_args[0] ?? 'probe' );
$hvc_k         = max( 1, (int) ( $hvc_args[1] ?? 5 ) );
$hvc_limit     = max( 1, (int) ( $hvc_args[2] ?? 200 ) );
$hvc_container = Plugin::instance()->container();

$hvc_retrieval = $hvc_container->get( RetrievalService::class );
$hvc_embedder  = $hvc_container->get( EmbeddingService::class );
$hvc_sources   = $hvc_container->get( KnowledgeSourceRepositoryInterface::class );
$hvc_chunks    = $hvc_container->get( ChunkRepositoryInterface::class );

echo "\nHiveclerk — end-to-end retrieval evaluation\n";
echo str_repeat( '=', 78 ) . "\n";

$hvc_sourceIds = array();
$hvc_totalChunks = 0;

foreach ( $hvc_sources->paginate( new Pagination( 1, Pagination::MAX_PER_PAGE ) ) as $hvc_source ) {
	if ( null !== $hvc_source->id && $hvc_source->chunkCount > 0 ) {
		$hvc_sourceIds[]  = $hvc_source->id;
		$hvc_totalChunks += $hvc_source->chunkCount;
	}
}

if ( array() === $hvc_sourceIds ) {
	echo "Nothing is indexed on this site, so there is nothing to evaluate.\n";
	echo "Index a knowledge source first, then run this again.\n";

	exit( 1 );
}

$hvc_pin = $hvc_retrieval->pinFor( $hvc_sourceIds );

if ( null === $hvc_pin ) {
	echo "No embedding provider is configured. Add a key under Settings → Providers.\n";

	exit( 1 );
}

printf(
	"%d sources · %s chunks · embedding with %s / %s\n\n",
	count( $hvc_sourceIds ),
	number_format( $hvc_totalChunks ),
	$hvc_pin->provider,
	$hvc_pin->model
);

/**
 * The most distinctive sentence of a chunk.
 *
 * Longest wins, which is a crude proxy for "most specific" and is
 * deliberately crude — a cleverer selection would be tuning the questions
 * to the retriever being measured.
 *
 * @param string $content Chunk text.
 * @return string
 */
function hvc_eval_probe( $content ) {
	$sentences = preg_split( '/(?<=[.!?])\s+/u', trim( $content ) ) ?: array();
	$best      = '';

	foreach ( $sentences as $sentence ) {
		$sentence = trim( $sentence );

		if ( mb_strlen( $sentence ) > mb_strlen( $best ) && mb_strlen( $sentence ) < 300 ) {
			$best = $sentence;
		}
	}

	return '' !== $best ? $best : mb_substr( trim( $content ), 0, 200 );
}

$hvc_questions = array();

if ( 'probe' === $hvc_mode ) {
	/*
	 * Sampled evenly across the corpus rather than taking the first N.
	 * The first N chunks all come from one or two documents, and recall
	 * measured over one document says nothing about a knowledge base.
	 */
	$hvc_pool = array();

	foreach ( $hvc_sourceIds as $hvc_sourceId ) {
		foreach ( $hvc_chunks->searchKeyword( 'the a of to and', array( $hvc_sourceId ), 5000 ) as $hvc_match ) {
			$hvc_pool[] = $hvc_match['chunk_id'];
		}
	}

	if ( array() === $hvc_pool ) {
		echo "Could not sample any chunks. Is the FULLTEXT index populated?\n";

		exit( 1 );
	}

	sort( $hvc_pool );

	$hvc_step = max( 1, (int) floor( count( $hvc_pool ) / $hvc_limit ) );

	for ( $hvc_i = 0; $hvc_i < count( $hvc_pool ) && count( $hvc_questions ) < $hvc_limit; $hvc_i += $hvc_step ) {
		$hvc_chunk = $hvc_chunks->find( $hvc_pool[ $hvc_i ] );

		if ( null === $hvc_chunk || mb_strlen( trim( $hvc_chunk->content ) ) < 60 ) {
			continue;
		}

		$hvc_questions[] = array(
			'question'  => hvc_eval_probe( $hvc_chunk->content ),
			'chunk_ids' => array( $hvc_chunk->id ),
		);
	}

	printf( "Probe mode: %d questions derived from the corpus itself.\n", count( $hvc_questions ) );
	echo "These share vocabulary with their targets, so treat the figure as an\n";
	echo "upper bound and a regression detector, not as real-world recall.\n\n";
} else {
	$hvc_path = file_exists( $hvc_mode ) ? $hvc_mode : ABSPATH . $hvc_mode;

	if ( ! file_exists( $hvc_path ) ) {
		printf( "No question file at %s.\n", $hvc_mode );

		exit( 1 );
	}

	$hvc_decoded = json_decode( (string) file_get_contents( $hvc_path ), true );

	if ( ! is_array( $hvc_decoded ) ) {
		echo "That question file is not valid JSON.\n";

		exit( 1 );
	}

	foreach ( $hvc_decoded as $hvc_entry ) {
		if ( ! is_array( $hvc_entry ) || ! isset( $hvc_entry['question'] ) ) {
			continue;
		}

		$hvc_questions[] = array(
			'question'     => (string) $hvc_entry['question'],
			'chunk_ids'    => array_map( 'intval', (array) ( $hvc_entry['chunk_ids'] ?? array() ) ),
			'document_ids' => array_map( 'intval', (array) ( $hvc_entry['document_ids'] ?? array() ) ),
		);
	}

	printf( "Curated set: %d questions from %s.\n\n", count( $hvc_questions ), basename( $hvc_path ) );
}

if ( array() === $hvc_questions ) {
	echo "No usable questions.\n";

	exit( 1 );
}

$hvc_options   = RetrievalOptions::of( topK: $hvc_k, useCache: false );
$hvc_hits      = 0;
$hvc_mrrTotal  = 0.0;
$hvc_latencies = array();
$hvc_embedMs   = array();
$hvc_confident = 0;
$hvc_misses    = array();

foreach ( $hvc_questions as $hvc_index => $hvc_question ) {
	$hvc_started = microtime( true );
	$hvc_result  = $hvc_retrieval->retrieve( $hvc_question['question'], $hvc_sourceIds, $hvc_options );
	$hvc_elapsed = ( microtime( true ) - $hvc_started ) * 1000;

	$hvc_latencies[] = $hvc_elapsed;
	$hvc_embedMs[]   = $hvc_result->diagnostics->embedMs;

	$hvc_expectedChunks = $hvc_question['chunk_ids'] ?? array();
	$hvc_expectedDocs   = $hvc_question['document_ids'] ?? array();

	$hvc_rank = 0;

	foreach ( $hvc_result->chunks as $hvc_position => $hvc_found ) {
		$hvc_matched = in_array( (int) $hvc_found->chunk->id, $hvc_expectedChunks, true )
			|| in_array( $hvc_found->chunk->documentId, $hvc_expectedDocs, true );

		if ( $hvc_matched ) {
			$hvc_rank = $hvc_position + 1;

			break;
		}
	}

	if ( $hvc_rank > 0 ) {
		++$hvc_hits;

		// Mean reciprocal rank, alongside recall. Recall says whether the
		// right chunk was in the top k; MRR says how near the top it was,
		// which is what decides whether the model actually reads it — the
		// prompt budget truncates the tail.
		$hvc_mrrTotal += 1 / $hvc_rank;
	} elseif ( count( $hvc_misses ) < 10 ) {
		$hvc_misses[] = sprintf(
			'%.60s… (best cosine %.3f)',
			$hvc_question['question'],
			$hvc_result->bestScore()
		);
	}

	if ( $hvc_result->bestScore() >= $hvc_options->threshold ) {
		++$hvc_confident;
	}

	if ( 0 === ( $hvc_index + 1 ) % 25 ) {
		printf( "   %d/%d…\n", $hvc_index + 1, count( $hvc_questions ) );
	}
}

sort( $hvc_latencies );

$hvc_count  = count( $hvc_questions );
$hvc_recall = $hvc_hits / $hvc_count;
$hvc_mrr    = $hvc_mrrTotal / $hvc_count;
$hvc_p95    = $hvc_latencies[ max( 0, (int) ceil( 0.95 * $hvc_count ) - 1 ) ];
$hvc_median = $hvc_latencies[ (int) floor( $hvc_count / 2 ) ];
$hvc_embedP = array_sum( $hvc_embedMs ) / max( 1, count( $hvc_embedMs ) );

echo "\n" . str_repeat( '─', 78 ) . "\n";
printf(
	"recall@%d      %.3f   %s (floor %.2f) — %d of %d questions\n",
	$hvc_k,
	$hvc_recall,
	$hvc_recall >= HVC_EVAL_RECALL_FLOOR ? 'PASS' : 'FAIL',
	HVC_EVAL_RECALL_FLOOR,
	$hvc_hits,
	$hvc_count
);
printf( "MRR           %.3f   (1.000 would be every answer ranked first)\n", $hvc_mrr );
printf(
	"above %.2f     %.1f%%   of questions had a match confident enough to answer from\n",
	$hvc_options->threshold,
	100 * $hvc_confident / $hvc_count
);
printf(
	"latency       p95 %.0f ms · median %.0f ms · of which %.0f ms is the embedding call\n",
	$hvc_p95,
	$hvc_median,
	$hvc_embedP
);

if ( array() !== $hvc_misses ) {
	echo "\nMissed, with the best cosine found:\n";

	foreach ( $hvc_misses as $hvc_miss ) {
		echo '  - ' . $hvc_miss . "\n";
	}

	echo "\nA miss with a high best-cosine is a ranking problem; a miss with a low one\n";
	echo "is a coverage problem, and no amount of retrieval tuning will fix it.\n";
}

echo "\n";
printf(
	"Cost of this run: %d embedding calls",
	$hvc_count
);

$hvc_cost = $hvc_embedder->estimateCost( $hvc_pin, $hvc_count * 30 );

if ( null !== $hvc_cost ) {
	printf( ', roughly $%.4f at %s prices', $hvc_cost, $hvc_pin->model );
}

echo ".\n";

if ( $hvc_recall < HVC_EVAL_RECALL_FLOOR ) {
	exit( 1 );
}
