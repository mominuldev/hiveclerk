<?php
/**
 * Topic grouping tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Analytics;

use Hiveclerk\Modules\Analytics\Support\TopicGrouper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What counts as the same question.
 *
 * The limits of word-overlap grouping are real, and the tests state them
 * as well as the successes — a screen that silently merged two different
 * questions would be worse than one that split them.
 *
 * @internal
 */
#[CoversClass( TopicGrouper::class )]
final class TopicGrouperTest extends TestCase {

	public function testWordOrderDoesNotSplitATopic(): void {
		self::assertSame(
			TopicGrouper::key( 'Do you ship to the EU?' ),
			TopicGrouper::key( 'EU — do you ship?' )
		);
	}

	public function testPluralsAreFoldedIntoTheirSingular(): void {
		// "trade account" and "trade accounts" are one question, and this
		// is the only word-shape change the grouper makes.
		self::assertSame(
			TopicGrouper::key( 'trade accounts' ),
			TopicGrouper::key( 'a trade account' )
		);
	}

	public function testVerbFormsAreNotMergedAndTheReportSaysSo(): void {
		// No stemming: "ship" and "shipping" stay apart. Merging them
		// needs a stemmer, and a stemmer also merges "rate" with
		// "rating" — two genuinely different questions on a shop.
		self::assertNotSame(
			TopicGrouper::key( 'do you ship to ireland' ),
			TopicGrouper::key( 'shipping to ireland' )
		);
	}

	public function testFunctionWordsAreIgnoredAndContentWordsAreNot(): void {
		self::assertSame( 'account offer trade', TopicGrouper::key( 'Do you offer trade accounts?' ) );
	}

	public function testGreetingsProduceNoKeyAndStayOutOfTheReport(): void {
		// Otherwise the top entry of every top-questions list is "hi".
		self::assertSame( '', TopicGrouper::key( 'hi' ) );
		self::assertSame( '', TopicGrouper::key( 'Hello there!' ) );
		self::assertSame( '', TopicGrouper::key( 'thanks' ) );
		self::assertSame( '', TopicGrouper::key( '   ' ) );
	}

	public function testDifferentSubjectsStayApart(): void {
		self::assertNotSame(
			TopicGrouper::key( 'what is your returns policy' ),
			TopicGrouper::key( 'what is your delivery policy' )
		);
	}

	public function testTheLabelIsTheVisitorsOwnWordingNotTheKey(): void {
		// "eu ship" is not something an operator can act on; the question
		// somebody actually typed is.
		self::assertSame(
			'Do you ship to the EU?',
			TopicGrouper::label( 'Do you   ship to the EU?' )
		);
	}

	public function testALongQuestionIsTruncatedRatherThanBreakingTheRow(): void {
		$label = TopicGrouper::label( str_repeat( 'word ', 40 ) );

		self::assertLessThanOrEqual( 90, mb_strlen( $label ) );
		self::assertStringEndsWith( '…', $label );
	}
}
