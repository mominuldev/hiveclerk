<?php
/**
 * Chunker tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\KnowledgeBase;

use Hiveclerk\Domain\Knowledge\Chunk;
use Hiveclerk\Modules\KnowledgeBase\Services\ChunkerService;
use Hiveclerk\Modules\KnowledgeBase\Text\ChunkOptions;
use Hiveclerk\Modules\KnowledgeBase\Text\NormalisedText;
use Hiveclerk\Modules\KnowledgeBase\Text\TextBlock;
use Hiveclerk\Modules\KnowledgeBase\Text\TokenEstimator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Chunk boundaries decide what retrieval can find, so most of these
 * tests assert properties that must hold for every input rather than
 * checking one expected output. A chunker that happens to produce the
 * right answer for the fixture in front of it is not evidence of much.
 *
 * @internal
 */
#[CoversClass( ChunkerService::class )]
#[CoversClass( ChunkOptions::class )]
#[CoversClass( NormalisedText::class )]
#[CoversClass( TokenEstimator::class )]
final class ChunkerServiceTest extends TestCase {

	private ChunkerService $chunker;

	protected function setUp(): void {
		parent::setUp();
		$this->chunker = new ChunkerService();
	}

	// ------------------------------------------------------------ invariants

	public function testEveryChunkIsALiteralSubstringOfTheDocument(): void {
		$document = $this->longDocument();

		$chunks = $this->chunker->chunk( $document, 1, 1, new ChunkOptions( 120 ) );

		$this->assertNotEmpty( $chunks );

		foreach ( $chunks as $chunk ) {
			// The whole point of working in offsets. If this ever fails,
			// char_start and char_end are decorative and a citation cannot
			// highlight what it cites.
			$this->assertSame(
				substr( $document->text, $chunk->charStart, $chunk->charEnd - $chunk->charStart ),
				$chunk->content,
				sprintf( 'Chunk %d does not match its own offsets.', $chunk->chunkIndex )
			);
		}
	}

	public function testEveryChunkIsValidUtf8(): void {
		// A single unbroken run of multi-byte characters: no spaces, no
		// sentence ends, nothing to split on but bytes.
		$document = NormalisedText::fromPlainText( str_repeat( '日本語のテキスト', 400 ) );

		$chunks = $this->chunker->chunk( $document, 1, 1, new ChunkOptions( 100 ) );

		$this->assertGreaterThan( 1, count( $chunks ) );

		foreach ( $chunks as $chunk ) {
			$this->assertTrue(
				mb_check_encoding( $chunk->content, 'UTF-8' ),
				sprintf( 'Chunk %d was cut inside a character.', $chunk->chunkIndex )
			);
		}
	}

	public function testTheWholeDocumentIsCovered(): void {
		$document = $this->longDocument();

		$chunks = $this->chunker->chunk( $document, 1, 1, new ChunkOptions( 120 ) );

		// Chunks may overlap, but no *content* may fall between them.
		// Anything in a gap is content the clerk can never retrieve, and
		// nothing else in the system would report it missing.
		//
		// Gaps of pure whitespace are expected and fine: spans cover block
		// bodies, so the two-byte separator between two sections belongs
		// to neither. Asserting strict contiguity instead of this failed
		// on exactly that, which is a test defect rather than a lost
		// paragraph — but only checking the difference proves it.
		$reached = 0;

		foreach ( $chunks as $chunk ) {
			if ( $chunk->charStart > $reached ) {
				$this->assertSame(
					'',
					trim( substr( $document->text, $reached, $chunk->charStart - $reached ) ),
					sprintf( 'Content was dropped before chunk %d.', $chunk->chunkIndex )
				);
			}

			$reached = max( $reached, $chunk->charEnd );
		}

		$this->assertSame(
			'',
			trim( substr( $document->text, $reached ) ),
			'Content was dropped after the last chunk.'
		);
	}

	public function testChunksStayWithinTheTokenBudget(): void {
		$estimator = new TokenEstimator();
		$options   = new ChunkOptions( 120 );

		$chunks = $this->chunker->chunk( $this->longDocument(), 1, 1, $options );

		foreach ( $chunks as $chunk ) {
			// The tail-folding rule can push the final chunk of a section
			// over by less than one minimum chunk, which is the trade it
			// exists to make.
			$this->assertLessThanOrEqual(
				$options->maxTokens + $options->minTokens,
				$estimator->estimate( $chunk->content ),
				sprintf( 'Chunk %d is over budget.', $chunk->chunkIndex )
			);
		}
	}

	public function testIndexesAreContiguousFromZero(): void {
		$chunks = $this->chunker->chunk( $this->longDocument(), 1, 1, new ChunkOptions( 120 ) );

		$this->assertSame(
			range( 0, count( $chunks ) - 1 ),
			array_map( static fn ( Chunk $c ): int => $c->chunkIndex, $chunks )
		);
	}

	// -------------------------------------------------------- heading-aware

	public function testChunksNeverSpanTwoHeadings(): void {
		$document = NormalisedText::fromBlocks(
			array(
				new TextBlock( 'Orders ship within two working days.', array( 'Help', 'Shipping' ) ),
				new TextBlock( 'Wholesale orders ship in fourteen days.', array( 'Help', 'Wholesale' ) ),
			)
		);

		// Both blocks together are far below the budget, so a chunker that
		// only counted tokens would merge them. It must not: the same
		// sentence means different things under the two headings, and a
		// vector averaging both matches neither.
		$chunks = $this->chunker->chunk( $document, 1, 1, new ChunkOptions( 800 ) );

		$this->assertCount( 2, $chunks );
		$this->assertSame( array( 'Help', 'Shipping' ), $chunks[0]->headingPath );
		$this->assertSame( array( 'Help', 'Wholesale' ), $chunks[1]->headingPath );
	}

	public function testTheHeadingPathTravelsWithTheChunk(): void {
		$document = NormalisedText::fromBlocks(
			array( new TextBlock( 'Free returns within 30 days.', array( 'Policies', 'Returns' ) ) )
		);

		$chunk = $this->chunker->chunk( $document, 1, 1 )[0];

		$this->assertSame( 'Policies > Returns', $chunk->path() );
		$this->assertStringStartsWith( "Policies > Returns\n\n", $chunk->contextualised() );
	}

	public function testOverlapDoesNotCrossASectionBoundary(): void {
		$first  = str_repeat( 'Alpha content about shipping. ', 40 );
		$second = 'Beta content about refunds.';

		$document = NormalisedText::fromBlocks(
			array(
				new TextBlock( $first, array( 'A' ) ),
				new TextBlock( $second, array( 'B' ) ),
			)
		);

		$chunks = $this->chunker->chunk( $document, 1, 1, new ChunkOptions( 60 ) );

		foreach ( $chunks as $chunk ) {
			if ( array( 'B' ) === $chunk->headingPath ) {
				$this->assertStringNotContainsString( 'Alpha', $chunk->content );
			} else {
				$this->assertStringNotContainsString( 'Beta', $chunk->content );
			}
		}
	}

	// --------------------------------------------------------------- overlap

	public function testConsecutiveChunksInASectionOverlap(): void {
		$document = NormalisedText::fromPlainText(
			implode(
				"\n\n",
				array_map(
					static fn ( int $i ): string => "Paragraph number {$i} explaining a policy in some detail.",
					range( 1, 30 )
				)
			)
		);

		$chunks = $this->chunker->chunk( $document, 1, 1, new ChunkOptions( 100, 0.2 ) );

		$this->assertGreaterThan( 2, count( $chunks ) );

		// Overlap is what makes a passage that straddles a boundary
		// findable from either side.
		$this->assertLessThan(
			$chunks[0]->charEnd,
			$chunks[1]->charStart,
			'The second chunk starts after the first ends — no overlap.'
		);
	}

	public function testZeroOverlapProducesDisjointChunks(): void {
		$document = $this->longDocument();

		$chunks = $this->chunker->chunk( $document, 1, 1, new ChunkOptions( 100, 0.0 ) );

		for ( $i = 1; $i < count( $chunks ); $i++ ) {
			$this->assertGreaterThanOrEqual( $chunks[ $i - 1 ]->charEnd, $chunks[ $i ]->charStart );
		}
	}

	public function testChunkingTerminatesWhenOverlapIsAtItsMaximum(): void {
		// The pathological case: overlap so large that a naive
		// implementation carries the whole chunk forward and never
		// advances. fromConfig caps it at 0.5 for this reason.
		$options = ChunkOptions::fromConfig(
			array(
				'chunk_tokens'  => 80,
				'chunk_overlap' => 0.95,
			)
		);

		$this->assertSame( 0.5, $options->overlap );

		$chunks = $this->chunker->chunk( $this->longDocument(), 1, 1, $options );

		$this->assertNotEmpty( $chunks );
		$this->assertLessThan( 500, count( $chunks ) );
	}

	// ------------------------------------------------------------ edge cases

	public function testAnEmptyDocumentProducesNoChunks(): void {
		$this->assertSame( array(), $this->chunker->chunk( NormalisedText::fromPlainText( '   ' ), 1, 1 ) );
	}

	public function testAShortDocumentIsOneChunk(): void {
		$document = NormalisedText::fromPlainText( 'We are open Monday to Friday, nine until five.' );

		$chunks = $this->chunker->chunk( $document, 1, 1 );

		$this->assertCount( 1, $chunks );
		$this->assertSame( $document->text, $chunks[0]->content );
	}

	public function testARuntTailIsFoldedIntoThePreviousChunk(): void {
		$body = implode(
			"\n\n",
			array_map(
				static fn ( int $i ): string => "Body paragraph {$i} with a reasonable amount of text in it.",
				range( 1, 12 )
			)
		);

		$document = NormalisedText::fromPlainText( $body . "\n\nOk." );

		$chunks = $this->chunker->chunk( $document, 1, 1, new ChunkOptions( 90, 0.0 ) );
		$last   = $chunks[ count( $chunks ) - 1 ];

		// "Ok." must not become a chunk of its own: a three-token vector
		// matches short queries better than the answer does.
		$this->assertStringEndsWith( 'Ok.', $last->content );
		$this->assertGreaterThan( 20, ( new TokenEstimator() )->estimate( $last->content ) );
	}

	public function testAParagraphOverBudgetIsSplitAtSentenceEnds(): void {
		$sentences = array_map(
			static fn ( int $i ): string => "This is sentence number {$i} and it says something.",
			range( 1, 40 )
		);

		$document = NormalisedText::fromPlainText( implode( ' ', $sentences ) );

		$chunks = $this->chunker->chunk( $document, 1, 1, new ChunkOptions( 80, 0.0 ) );

		$this->assertGreaterThan( 1, count( $chunks ) );

		foreach ( $chunks as $chunk ) {
			$this->assertStringEndsWith(
				'.',
				rtrim( $chunk->content ),
				'A chunk ended mid-sentence when a sentence boundary was available.'
			);
		}
	}

	// ------------------------------------------------------------- estimator

	public function testCjkIsNotUnderCounted(): void {
		$estimator = new TokenEstimator();

		$english = $estimator->estimate( str_repeat( 'word ', 100 ) );
		$chinese = $estimator->estimate( str_repeat( '文字', 100 ) );

		// 200 CJK characters cost roughly 200 tokens. Under the naive
		// bytes-over-four rule they would be counted as 150 — and under a
		// characters-over-four rule, 50 — which is how a chunk lands four
		// times over the embedding endpoint's limit.
		$this->assertGreaterThan( 150, $chinese );
		$this->assertGreaterThan( 100, $english );
	}

	public function testAnUnbrokenStringIsCountedByLength(): void {
		$estimator = new TokenEstimator();

		// One "word", 500 characters. A word-count estimate would call
		// this one token.
		$this->assertGreaterThan( 100, $estimator->estimate( str_repeat( 'a', 500 ) ) );
	}

	public function testEmptyTextCostsNothing(): void {
		$this->assertSame( 0, ( new TokenEstimator() )->estimate( "  \n\t " ) );
	}

	// ----------------------------------------------------------------- setup

	/**
	 * A document with several headed sections and enough text to split.
	 *
	 * @return NormalisedText
	 */
	private function longDocument(): NormalisedText {
		$blocks = array();

		foreach ( array( 'Shipping', 'Returns', 'Payment' ) as $section ) {
			for ( $i = 1; $i <= 6; $i++ ) {
				$blocks[] = new TextBlock(
					"Paragraph {$i} of the {$section} section. It explains the policy in "
						. 'enough words that the chunker has something to divide, and it ends here.',
					array( 'Help centre', $section )
				);
			}
		}

		return NormalisedText::fromBlocks( $blocks );
	}
}
