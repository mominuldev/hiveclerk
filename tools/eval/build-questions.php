<?php
/**
 * Turns questions.source.json into the document-id form the harness reads.
 *
 * Run with:  wp eval-file tools/eval/build-questions.php
 *
 * Document ids change every time the corpus is re-indexed, so a question
 * file full of literal integers goes stale the moment somebody re-indexes —
 * and it fails as a *recall miss* rather than as an error, which is the worst
 * possible failure mode for a measurement tool. Titles are stable; this
 * resolves them, and shouts if any title has no document.
 *
 * @package Hiveclerk
 */

$hvc_dir    = WP_CONTENT_DIR . '/plugins/hiveclerk/tools/eval/';
$hvc_source = json_decode( (string) file_get_contents( $hvc_dir . 'questions.source.json' ), true );

global $wpdb;

$hvc_by_title = array();

foreach ( (array) $wpdb->get_results( "SELECT id, title FROM {$wpdb->prefix}hvc_documents" ) as $hvc_doc ) {
	$hvc_by_title[ (string) $hvc_doc->title ][] = (int) $hvc_doc->id;
}

$hvc_out     = array();
$hvc_missing = array();

foreach ( $hvc_source['questions'] as $hvc_entry ) {
	$hvc_page = (string) $hvc_entry['page'];

	if ( ! isset( $hvc_by_title[ $hvc_page ] ) ) {
		$hvc_missing[ $hvc_page ] = true;

		continue;
	}

	$hvc_out[] = array(
		'question'     => (string) $hvc_entry['q'],
		'document_ids' => $hvc_by_title[ $hvc_page ],
	);
}

if ( array() !== $hvc_missing ) {
	echo "MISSING DOCUMENTS — these pages are not indexed:\n";

	foreach ( array_keys( $hvc_missing ) as $hvc_title ) {
		echo "  - {$hvc_title}\n";
	}

	echo "\nThe corpus is incomplete. Re-seed and re-index before measuring;\n";
	echo "a question whose target is absent counts as a miss and understates recall.\n";
}

file_put_contents(
	$hvc_dir . 'questions.json',
	(string) wp_json_encode( $hvc_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
);

printf( "%d questions written to tools/eval/questions.json\n", count( $hvc_out ) );
