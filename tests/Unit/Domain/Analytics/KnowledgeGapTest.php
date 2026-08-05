<?php
/**
 * Knowledge gap tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Domain\Analytics;

use Hiveclerk\Domain\Analytics\GapStatus;
use Hiveclerk\Domain\Analytics\KnowledgeGap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass( KnowledgeGap::class )]
#[CoversClass( GapStatus::class )]
final class KnowledgeGapTest extends TestCase {

	public function testWordingAndPunctuationDoNotSplitAGapInTwo(): void {
		self::assertSame(
			KnowledgeGap::hash( 'Do you offer trade accounts?' ),
			KnowledgeGap::hash( 'do you offer   trade accounts' )
		);
	}

	public function testDifferentQuestionsStayDifferent(): void {
		// Nothing more aggressive than case and punctuation. Stemming
		// would collapse "returns for damaged goods" and "returns for
		// unwanted goods", and they are two different pages to write.
		self::assertNotSame(
			KnowledgeGap::hash( 'returns for damaged goods' ),
			KnowledgeGap::hash( 'returns for unwanted goods' )
		);
	}

	public function testAGreetingIsNotAKnowledgeGap(): void {
		// A worklist full of "hi" is a worklist nobody opens.
		self::assertFalse( self::gap( 'hi' )->isWorthAnswering() );
		self::assertFalse( self::gap( '???' )->isWorthAnswering() );
		self::assertFalse( self::gap( 'thanks' )->isWorthAnswering() );
	}

	public function testAShortRealQuestionStillCounts(): void {
		self::assertTrue( self::gap( 'trade accounts available?' )->isWorthAnswering() );
	}

	public function testFoundNothingDistinguishesAWeakMatchFromNoMatch(): void {
		// Different problems with different fixes: the first wants new
		// content, the second usually wants an existing page to use the
		// word the visitor used.
		self::assertTrue( KnowledgeGap::record( 1, 'do you deliver on sundays', null )->foundNothing() );
		self::assertFalse( KnowledgeGap::record( 1, 'do you deliver on sundays', 0.34 )->foundNothing() );
	}

	public function testANewGapOpensAtOneOccurrence(): void {
		$gap = KnowledgeGap::record( 7, 'Can I collect from your Berlin store?', 0.12, 41 );

		self::assertSame( 7, $gap->agentId );
		self::assertSame( 41, $gap->conversationId );
		self::assertSame( 1, $gap->occurrences );
		self::assertSame( GapStatus::Open, $gap->status );
	}

	public function testStatusFallsBackToOpenRatherThanThrowing(): void {
		self::assertSame( GapStatus::Open, GapStatus::fromStorage( 'something-else' ) );
		self::assertSame( GapStatus::Ignored, GapStatus::fromStorage( 'ignored' ) );
		self::assertTrue( GapStatus::Open->isOpen() );
		self::assertFalse( GapStatus::Resolved->isOpen() );
	}

	/**
	 * A gap with the given question.
	 *
	 * @param string $question Question.
	 * @return KnowledgeGap
	 */
	private static function gap( string $question ): KnowledgeGap {
		return KnowledgeGap::record( 1, $question, null );
	}
}
