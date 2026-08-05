<?php
/**
 * FAQ CSV import tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\KnowledgeBase;

use Hiveclerk\Modules\KnowledgeBase\Extractors\FaqExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the import understands, and what it quietly did not.
 *
 * The citation column was recognised only when it was headed exactly `url`.
 * A file headed "Question, Answer, Source" — which is what most help-desk
 * exports look like — imported every pair with no citation and reported
 * complete success: the answers were right, and the "where did this come
 * from" link was absent on all of them. Nothing in the result said so,
 * because `skipped` counts rows that were dropped, not columns that were
 * ignored.
 *
 * @internal
 */
#[CoversClass( FaqExtractor::class )]
final class FaqCsvImportTest extends TestCase {

	public function testAHeaderRowIsDetectedAndSkipped(): void {
		$result = FaqExtractor::parseCsv( "Question,Answer\nDo you ship?,Yes.\n" );

		self::assertCount( 1, $result['pairs'] );
		self::assertSame( 'Do you ship?', $result['pairs'][0]['question'] );
	}

	public function testRowsMissingEitherHalfAreSkippedAndCounted(): void {
		$csv = "Question,Answer\nGood,Pair.\nNo answer,\n,No question\n";

		$result = FaqExtractor::parseCsv( $csv );

		// A question with no answer would index a chunk that matches a query
		// perfectly and says nothing, which is worse than not matching.
		self::assertCount( 1, $result['pairs'] );
		self::assertSame( 2, $result['skipped'] );
	}

	/**
	 * @param string $heading Column heading for the citation.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'citationHeadings' )]
	public function testTheCitationColumnIsFoundUnderTheNamesPeopleUse( string $heading ): void {
		$csv = "Question,Answer,{$heading}\nDo you ship?,Yes.,https://example.com/delivery\n";

		$result = FaqExtractor::parseCsv( $csv );

		self::assertSame( 'https://example.com/delivery', $result['pairs'][0]['url'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function citationHeadings(): array {
		return array(
			'url'        => array( 'URL' ),
			'source'     => array( 'Source' ),
			'source url' => array( 'Source URL' ),
			'source_url' => array( 'source_url' ),
			'link'       => array( 'Link' ),
			'page'       => array( 'Page' ),
		);
	}

	public function testAHeaderlessThirdColumnIsUsedOnlyWhenItLooksLikeAUrl(): void {
		$withUrl = FaqExtractor::parseCsv( "Do you ship?,Yes.,https://example.com/delivery\n" );
		self::assertSame( 'https://example.com/delivery', $withUrl['pairs'][0]['url'] );

		// A third column in a headerless file could be anything — a category,
		// an author, a ticket id. Promoting that to a citation puts a broken
		// link under an answer, which reads as a product bug.
		$withJunk = FaqExtractor::parseCsv( "Do you ship?,Yes.,Delivery team\n" );
		self::assertSame( '', $withJunk['pairs'][0]['url'] );
	}

	public function testATwoColumnFileWithNoHeaderStillImports(): void {
		$result = FaqExtractor::parseCsv( "Do you ship?,Yes.\nReturns?,30 days.\n" );

		self::assertCount( 2, $result['pairs'] );
		self::assertSame( 0, $result['skipped'] );
	}

	public function testACommaInsideAQuotedAnswerSurvives(): void {
		$result = FaqExtractor::parseCsv( "Question,Answer\nShip?,\"Yes, in four days.\"\n" );

		self::assertSame( 'Yes, in four days.', $result['pairs'][0]['answer'] );
	}

	public function testAnExcelByteOrderMarkDoesNotBreakHeaderDetection(): void {
		$result = FaqExtractor::parseCsv( "\xEF\xBB\xBFQuestion,Answer\nShip?,Yes.\n" );

		// Without the BOM strip the first heading is not "question", no header
		// is recognised, and the heading row itself becomes a pair.
		self::assertCount( 1, $result['pairs'] );
		self::assertSame( 'Ship?', $result['pairs'][0]['question'] );
	}
}
