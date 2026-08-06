<?php
/**
 * Splits documents into retrievable chunks.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Services;

use Hiveclerk\Domain\Knowledge\Chunk;
use Hiveclerk\Modules\KnowledgeBase\Text\ChunkOptions;
use Hiveclerk\Modules\KnowledgeBase\Text\NormalisedText;
use Hiveclerk\Modules\KnowledgeBase\Text\TextSpan;
use Hiveclerk\Modules\KnowledgeBase\Text\TokenEstimator;

/**
 * Turns a normalised document into chunks (FR-KB-06).
 *
 * Chunk boundaries decide what retrieval can find, so this is one of the
 * few places where a purely mechanical choice has a visible effect on
 * how good the product feels. Three rules, in order of priority:
 *
 * 1. **Never merge across headings.** Two sections under different
 *    headings are about different things. A vector averaging both
 *    matches neither well, and the failure surfaces as the clerk not
 *    knowing something that is plainly on the page.
 * 2. **Split at the largest boundary that fits** — sections, then
 *    paragraphs, then sentences, then characters. A chunk ending
 *    mid-sentence retrieves as a fragment the model has to guess at.
 * 3. **Overlap within a section only.** Overlap exists so a passage
 *    spanning a boundary is retrievable from either side. Carrying the
 *    tail of one section into an unrelated one just adds noise to the
 *    second chunk's vector.
 * 4. **Aim for the target, never exceed the ceiling.** A chunk is
 *    retrieved whole, so its size decides how much unrelated text a
 *    visitor's question has to compete with. `maxTokens` is what the
 *    embedding endpoint accepts; `targetTokens` is what retrieves well,
 *    and packing to the former is what made a flat page score 0.889
 *    where the same prose under sub-headings scored 0.944.
 *
 * Everything works on byte offsets into the assembled document, so every
 * chunk is a literal substring of it. That is what makes char_start and
 * char_end usable for highlighting a citation later.
 */
final class ChunkerService {

	/**
	 * Construct.
	 *
	 * @param TokenEstimator $tokens Token estimator.
	 */
	public function __construct(
		private readonly TokenEstimator $tokens = new TokenEstimator()
	) {
	}

	/**
	 * Chunk a document.
	 *
	 * @param NormalisedText    $document   Assembled text and its spans.
	 * @param int               $documentId Owning document.
	 * @param int               $sourceId   Owning source.
	 * @param ChunkOptions|null $options    Parameters.
	 * @return array<int, Chunk>
	 */
	public function chunk(
		NormalisedText $document,
		int $documentId,
		int $sourceId,
		?ChunkOptions $options = null
	): array {
		$options = $options ?? new ChunkOptions();

		if ( $document->isEmpty() ) {
			return array();
		}

		$chunks = array();
		$index  = 0;

		foreach ( $this->sections( $document ) as $section ) {
			foreach ( $this->chunkSection( $document, $section, $options ) as $span ) {
				$content = $document->slice( $span->start, $span->end );

				if ( '' === trim( $content ) ) {
					continue;
				}

				$chunks[] = new Chunk(
					id: null,
					documentId: $documentId,
					sourceId: $sourceId,
					chunkIndex: $index,
					content: $content,
					headingPath: $span->headingPath,
					tokenCount: $this->tokens->estimate( $content ),
					charStart: $span->start,
					charEnd: $span->end,
				);

				++$index;
			}
		}

		return $chunks;
	}

	/**
	 * Group consecutive spans that share a heading path.
	 *
	 * @param NormalisedText $document Document.
	 * @return array<int, array<int, TextSpan>>
	 */
	private function sections( NormalisedText $document ): array {
		$sections = array();
		$current  = array();
		$key      = null;

		foreach ( $document->spans as $span ) {
			if ( null !== $key && $span->pathKey() !== $key ) {
				$sections[] = $current;
				$current    = array();
			}

			$key       = $span->pathKey();
			$current[] = $span;
		}

		if ( array() !== $current ) {
			$sections[] = $current;
		}

		return $sections;
	}

	/**
	 * Pack one section's spans into chunk-sized spans.
	 *
	 * @param NormalisedText      $document Document.
	 * @param array<int, TextSpan> $section Spans sharing a heading path.
	 * @param ChunkOptions        $options  Parameters.
	 * @return array<int, TextSpan>
	 */
	private function chunkSection( NormalisedText $document, array $section, ChunkOptions $options ): array {
		$units = $this->units( $document, $section, $options );

		if ( array() === $units ) {
			return array();
		}

		$path    = $units[0]->headingPath;
		$chunks  = array();
		$pending = array();
		$budget  = 0;

		foreach ( $units as $unit ) {
			$cost = $this->cost( $document, $unit );

			// Flush before adding, not after. Adding first and checking
			// afterwards produces chunks one unit over budget, which for a
			// long paragraph is a long way over.
			//
			// Against the target, not the ceiling: the ceiling is what an
			// embedding endpoint accepts, and packing to it buries a
			// two-sentence fact inside eight hundred tokens of everything
			// else on the page. A single unit larger than the target still
			// passes through whole — units() has already guaranteed it is
			// under the ceiling, and there is no boundary left inside it
			// worth cutting on.
			if ( array() !== $pending && $budget + $cost > $options->targetTokens ) {
				$chunks[] = $this->joinSpans( $pending, $path );
				$pending  = $this->overlapFrom( $document, $pending, $options );
				$budget   = $this->costOfAll( $document, $pending );
			}

			$pending[] = $unit;
			$budget   += $cost;
		}

		// No emptiness check: the loop above appends on every iteration and
		// the method returned early when there were no units, so there is
		// always a partial chunk left to flush here.
		$chunks[] = $this->joinSpans( $pending, $path );

		return $this->foldShortTail( $document, $chunks, $options );
	}

	/**
	 * Break a section's spans down until every unit fits the budget.
	 *
	 * Paragraphs first, since they are the boundary a reader would pick.
	 * A paragraph over the *target* is split at sentence ends, because a
	 * wall-of-text page with no headings and no paragraph breaks is
	 * exactly the shape that retrieves worst and sentences are the only
	 * boundary it offers. A sentence over the *ceiling* — a minified
	 * script that survived extraction, a table flattened into one line —
	 * is cut at a fixed width, because at that point there is no boundary
	 * left to respect.
	 *
	 * The two thresholds are deliberately different. Splitting sentences
	 * at the target as well would cut mid-thought to save a few tokens; a
	 * sentence is a unit whether or not it is a convenient size.
	 *
	 * @param NormalisedText       $document Document.
	 * @param array<int, TextSpan> $section  Spans.
	 * @param ChunkOptions         $options  Parameters.
	 * @return array<int, TextSpan>
	 */
	private function units( NormalisedText $document, array $section, ChunkOptions $options ): array {
		$units = array();

		foreach ( $section as $span ) {
			if ( $this->cost( $document, $span ) <= $options->targetTokens ) {
				$units[] = $span;
				continue;
			}

			foreach ( $this->sentences( $document, $span ) as $sentence ) {
				if ( $this->cost( $document, $sentence ) <= $options->maxTokens ) {
					$units[] = $sentence;
					continue;
				}

				foreach ( $this->hardSplit( $document, $sentence, $options ) as $piece ) {
					$units[] = $piece;
				}
			}
		}

		return $units;
	}

	/**
	 * Split a span at sentence boundaries.
	 *
	 * @param NormalisedText $document Document.
	 * @param TextSpan       $span     Span.
	 * @return array<int, TextSpan>
	 */
	private function sentences( NormalisedText $document, TextSpan $span ): array {
		$text = $document->slice( $span->start, $span->end );

		// Split after terminal punctuation followed by whitespace, keeping
		// the punctuation with the sentence it ends. CJK full stops are
		// included; they are not followed by a space, so the lookahead
		// allows a bare boundary for those.
		$parts = preg_split(
			'/(?<=[.!?])\s+|(?<=[。！？])/u',
			$text,
			-1,
			PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE
		);

		if ( false === $parts || array() === $parts ) {
			return array( $span );
		}

		$spans = array();

		foreach ( $parts as $part ) {
			$offset = $span->start + (int) $part[1];
			$length = strlen( (string) $part[0] );

			if ( 0 === $length ) {
				continue;
			}

			$spans[] = new TextSpan( $offset, $offset + $length, $span->headingPath );
		}

		return array() === $spans ? array( $span ) : $spans;
	}

	/**
	 * Cut a span with no usable boundary into pieces that fit.
	 *
	 * The width is derived from this span's own density rather than from
	 * a global characters-per-token constant. The constant is calibrated
	 * on English, and the only reason execution reaches this method is
	 * that the text is already unusual — a flattened table, a base64
	 * blob, or a page of Japanese, where four characters per token is out
	 * by a factor of four and every piece would land over budget.
	 *
	 * @param NormalisedText $document Document.
	 * @param TextSpan       $span     Span.
	 * @param ChunkOptions   $options  Parameters.
	 * @return array<int, TextSpan>
	 */
	private function hardSplit( NormalisedText $document, TextSpan $span, ChunkOptions $options ): array {
		$cost = $this->cost( $document, $span );

		if ( $cost <= $options->maxTokens || $span->length() < 2 ) {
			return array( $span );
		}

		// Ten percent of headroom, because the estimate is an estimate and
		// the direction that hurts is over.
		$ratio = ( $options->maxTokens / $cost ) * 0.9;
		$width = max( 1, (int) floor( $span->length() * $ratio ) );

		$spans = array();
		$start = $span->start;

		while ( $start < $span->end ) {
			$end = $this->snapToCharacter( $document, min( $span->end, $start + $width ), $span->end );

			if ( $end <= $start ) {
				$end = $span->end;
			}

			$spans[] = new TextSpan( $start, $end, $span->headingPath );
			$start   = $end;
		}

		return $spans;
	}

	/**
	 * Move an offset forward to the next character boundary.
	 *
	 * Cutting inside a multi-byte sequence produces invalid UTF-8. MySQL
	 * rejects it on insert, so the failure is at least loud — but it
	 * fails the whole document over one accented character, and the site
	 * it happens on is never the one being tested.
	 *
	 * @param NormalisedText $document Document.
	 * @param int            $offset   Proposed offset.
	 * @param int            $limit    Never move past this.
	 * @return int
	 */
	private function snapToCharacter( NormalisedText $document, int $offset, int $limit ): int {
		// A UTF-8 continuation byte matches 10xxxxxx. Landing on one means
		// the offset is inside a character, so step forward until it is not.
		while ( $offset < $limit && 0x80 === ( ord( $document->text[ $offset ] ) & 0xC0 ) ) {
			++$offset;
		}

		return $offset;
	}

	/**
	 * Choose the units that carry over into the next chunk.
	 *
	 * @param NormalisedText       $document Document.
	 * @param array<int, TextSpan> $units    Units just flushed.
	 * @param ChunkOptions         $options  Parameters.
	 * @return array<int, TextSpan>
	 */
	private function overlapFrom( NormalisedText $document, array $units, ChunkOptions $options ): array {
		$budget = $options->overlapTokens();

		if ( $budget <= 0 || count( $units ) < 2 ) {
			return array();
		}

		$carried = array();
		$spent   = 0;

		// Never carry the whole chunk forward. If every unit fitted inside
		// the overlap budget the next chunk would start where the last one
		// did and the loop would not advance.
		$limit = count( $units ) - 1;

		for ( $i = count( $units ) - 1; $i >= count( $units ) - $limit; $i-- ) {
			$cost = $this->cost( $document, $units[ $i ] );

			if ( $spent + $cost > $budget ) {
				break;
			}

			array_unshift( $carried, $units[ $i ] );
			$spent += $cost;
		}

		return $carried;
	}

	/**
	 * Merge a runt final chunk into the one before it.
	 *
	 * Only within a section, and only at the end, which is the only place
	 * a runt can appear: every other chunk was flushed because the next
	 * unit did not fit, so it is full by construction.
	 *
	 * @param NormalisedText       $document Document.
	 * @param array<int, TextSpan> $chunks   Chunk spans.
	 * @param ChunkOptions         $options  Parameters.
	 * @return array<int, TextSpan>
	 */
	private function foldShortTail( NormalisedText $document, array $chunks, ChunkOptions $options ): array {
		$count = count( $chunks );

		if ( $count < 2 ) {
			return $chunks;
		}

		$last = $chunks[ $count - 1 ];

		if ( $this->cost( $document, $last ) >= $options->minTokens ) {
			return $chunks;
		}

		$previous = $chunks[ $count - 2 ];

		$chunks[ $count - 2 ] = new TextSpan(
			$previous->start,
			max( $previous->end, $last->end ),
			$previous->headingPath
		);

		array_pop( $chunks );

		return $chunks;
	}

	/**
	 * Combine units into the span they cover.
	 *
	 * The span runs from the first unit's start to the last unit's end,
	 * which includes the separators between them — that is deliberate,
	 * and it is what keeps the slice identical to the document text.
	 *
	 * @param array<int, TextSpan> $units Units.
	 * @param array<int, string>   $path  Heading path.
	 * @return TextSpan
	 */
	private function joinSpans( array $units, array $path ): TextSpan {
		$first = $units[0];
		$last  = $units[ count( $units ) - 1 ];

		return new TextSpan( $first->start, $last->end, $path );
	}

	/**
	 * Token cost of a span.
	 *
	 * @param NormalisedText $document Document.
	 * @param TextSpan       $span     Span.
	 * @return int
	 */
	private function cost( NormalisedText $document, TextSpan $span ): int {
		return $this->tokens->estimate( $document->slice( $span->start, $span->end ) );
	}

	/**
	 * Token cost of several spans.
	 *
	 * @param NormalisedText       $document Document.
	 * @param array<int, TextSpan> $spans    Spans.
	 * @return int
	 */
	private function costOfAll( NormalisedText $document, array $spans ): int {
		$total = 0;

		foreach ( $spans as $span ) {
			$total += $this->cost( $document, $span );
		}

		return $total;
	}
}
