<?php
/**
 * FAQ pairs and CSV import.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Extractors;

use Hiveclerk\Domain\Knowledge\KnowledgeSource;
use Hiveclerk\Domain\Knowledge\SourceType;
use Hiveclerk\Modules\KnowledgeBase\Text\NormalisedText;
use Hiveclerk\Modules\KnowledgeBase\Text\TextBlock;

/**
 * Indexes question-and-answer pairs (FR-KB-04).
 *
 * ## One document per pair
 *
 * Not one document containing every pair. A question and its answer are
 * a complete unit of meaning, and keeping them as one small document
 * means a retrieved chunk is the whole answer rather than the end of one
 * answer and the start of the next. It is the highest-precision source
 * type in the product, and customers use it for exactly the questions
 * they cannot afford to have answered vaguely.
 *
 * ## The question is part of the indexed text
 *
 * A visitor's phrasing is far closer to the question than to the answer.
 * "Do you ship to Ireland?" matches the stored question almost exactly
 * and the answer — "Yes, delivery is £8 and takes four days" — hardly at
 * all.
 */
final class FaqExtractor extends AbstractExtractor {

	public function type(): SourceType {
		return SourceType::Faq;
	}

	public function estimate( KnowledgeSource $source ): int {
		return count( $this->pairs( $source ) );
	}

	/**
	 * Yield one document per question.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return iterable<int, ExtractedDocument>
	 */
	public function extract( KnowledgeSource $source ): iterable {
		foreach ( $this->pairs( $source ) as $index => $pair ) {
			yield new ExtractedDocument(
				// Positional rather than content-derived, so editing an
				// answer updates its document instead of orphaning the old
				// one and inserting a new one beside it.
				externalId: sprintf( 'faq-%d-%d', (int) $source->id, $index ),
				title: $pair['question'],
				text: NormalisedText::fromBlocks(
					array(
						new TextBlock( $pair['question'], array( $source->name ) ),
						new TextBlock( $pair['answer'], array( $source->name ) ),
					)
				),
				url: $pair['url'],
				metadata: array(
					'kind'     => 'faq',
					'question' => $pair['question'],
				),
			);
		}
	}

	/**
	 * Read the pairs a source holds.
	 *
	 * Accepts both the editor's own structure and a parsed CSV, because
	 * they are the same data and the difference is only how it arrived.
	 *
	 * @param KnowledgeSource $source Source.
	 * @return array<int, array{question: string, answer: string, url: string}>
	 */
	private function pairs( KnowledgeSource $source ): array {
		$raw = $source->config['pairs'] ?? null;

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$pairs = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$question = isset( $entry['question'] ) && is_string( $entry['question'] )
				? trim( $entry['question'] )
				: '';

			$answer = isset( $entry['answer'] ) && is_string( $entry['answer'] )
				? trim( $entry['answer'] )
				: '';

			// Both halves are required. A question with no answer indexes
			// a chunk that matches the query perfectly and says nothing,
			// which is worse than not matching at all.
			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$pairs[] = array(
				'question' => $question,
				'answer'   => $answer,
				'url'      => isset( $entry['url'] ) && is_string( $entry['url'] ) ? $entry['url'] : '',
			);
		}

		return $pairs;
	}

	/**
	 * Parse an uploaded CSV into pairs (FR-KB-04 import).
	 *
	 * Static and public because the REST layer calls it at upload time:
	 * a customer needs to see what was understood before it is saved, and
	 * "we imported 240 rows and ignored 3" is only answerable if parsing
	 * happens before storage.
	 *
	 * @param string $csv Raw CSV.
	 * @return array{pairs: array<int, array{question: string, answer: string, url: string}>, skipped: int}
	 */
	public static function parseCsv( string $csv ): array {
		$handle = fopen( 'php://temp', 'r+' );

		if ( false === $handle ) {
			return array(
				'pairs'   => array(),
				'skipped' => 0,
			);
		}

		// Byte-order marks come with every export from Excel and would
		// otherwise become part of the first column's name, so the header
		// never matches and every row is skipped.
		fwrite( $handle, preg_replace( '/^\xEF\xBB\xBF/', '', $csv ) ?? $csv );
		rewind( $handle );

		$pairs   = array();
		$skipped = 0;
		$header  = null;

		while ( true ) {
			$row = fgetcsv( $handle, 0, ',', '"', '\\' );

			if ( false === $row ) {
				break;
			}

			if ( null === $header ) {
				$header = self::header( $row );

				// A file with no recognisable header is treated as
				// question-then-answer, which is what a two-column export
				// looks like and what most people paste.
				if ( null === $header ) {
					$header = array(
						'question' => 0,
						'answer'   => 1,
						'url'      => null,
					);
				} else {
					continue;
				}
			}

			$question = self::cell( $row, $header['question'] );
			$answer   = self::cell( $row, $header['answer'] );

			if ( '' === $question || '' === $answer ) {
				++$skipped;

				continue;
			}

			$pairs[] = array(
				'question' => $question,
				'answer'   => $answer,
				'url'      => self::cell( $row, $header['url'] ),
			);
		}

		fclose( $handle );

		return array(
			'pairs'   => $pairs,
			'skipped' => $skipped,
		);
	}

	/**
	 * Work out which column is which.
	 *
	 * @param array<int, string|null> $row First row.
	 * @return array{question: int, answer: int, url: int|null}|null
	 */
	private static function header( array $row ): ?array {
		$map = array();

		foreach ( $row as $index => $value ) {
			$map[ strtolower( trim( (string) $value ) ) ] = $index;
		}

		$question = $map['question'] ?? $map['q'] ?? null;
		$answer   = $map['answer'] ?? $map['a'] ?? null;

		if ( null === $question || null === $answer ) {
			return null;
		}

		return array(
			'question' => (int) $question,
			'answer'   => (int) $answer,
			'url'      => isset( $map['url'] ) ? (int) $map['url'] : null,
		);
	}

	/**
	 * Read one cell.
	 *
	 * @param array<int, string|null> $row   Row.
	 * @param int|null                $index Column, or null.
	 * @return string
	 */
	private static function cell( array $row, ?int $index ): string {
		if ( null === $index || ! isset( $row[ $index ] ) ) {
			return '';
		}

		return trim( (string) $row[ $index ] );
	}
}
