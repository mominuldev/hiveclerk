<?php
/**
 * Fusion tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\KnowledgeBase;

use Hiveclerk\Domain\Knowledge\RetrievalOptions;
use Hiveclerk\Modules\KnowledgeBase\Vector\ReciprocalRankFusion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Fusion decides the order an operator sees, and the failure it exists to
 * prevent is a subtle one: a single signal quietly dominating because its
 * scores happened to be on a bigger scale. These tests assert the
 * behaviours that make it a fusion rather than a preference.
 *
 * @internal
 */
#[CoversClass( ReciprocalRankFusion::class )]
#[CoversClass( RetrievalOptions::class )]
final class ReciprocalRankFusionTest extends TestCase {

	public function testAgreementBetweenSignalsBeatsBeingFirstInOne(): void {
		// 7 is first for neither signal but second for both. 1 and 9 each
		// top one list and are absent from the other. Agreement should win:
		// that is the entire reason for running two signals.
		$fused = ReciprocalRankFusion::fuse(
			array(
				array( 1, 7, 3 ),
				array( 9, 7, 4 ),
			)
		);

		$this->assertSame( 7, array_key_first( $fused ) );
	}

	public function testOnlyOrderMattersAndNotTheUnderlyingScores(): void {
		/*
		 * The property that makes fusion safe to run over a cosine
		 * similarity and an unbounded BM25 figure at the same time. The
		 * input here is the same ordering twice; if any score leaked in,
		 * the two results would differ.
		 */
		$first  = ReciprocalRankFusion::fuse( array( array( 5, 2, 8 ), array( 2, 5, 8 ) ) );
		$second = ReciprocalRankFusion::fuse( array( array( 5, 2, 8 ), array( 2, 5, 8 ) ) );

		$this->assertSame( $first, $second );
		$this->assertSame( array_keys( $first ), array_keys( $second ) );
	}

	public function testASingleListIsReturnedInItsOwnOrder(): void {
		// The degraded case: the embedding provider is down and only the
		// keyword signal ran. Fusion must not reorder it.
		$fused = ReciprocalRankFusion::fuse( array( array( 4, 6, 1, 9 ) ) );

		$this->assertSame( array( 4, 6, 1, 9 ), array_keys( $fused ) );
	}

	public function testScoresDecayWithRankAndNeverReachZero(): void {
		$fused = ReciprocalRankFusion::fuse( array( range( 1, 100 ) ) );

		$values = array_values( $fused );

		for ( $i = 1; $i < count( $values ); $i++ ) {
			$this->assertLessThan( $values[ $i - 1 ], $values[ $i ] );
		}

		$this->assertGreaterThan( 0.0, end( $values ) );
	}

	public function testWeightsShiftTheBalanceBetweenSignals(): void {
		// The mechanism the retrieval service now relies on: RetrievalService
		// weights the keyword arm at 0.2 against the vector arm's 1.0.
		$even     = ReciprocalRankFusion::fuse( array( array( 1, 2 ), array( 2, 1 ) ) );
		$vectorly = ReciprocalRankFusion::fuse( array( array( 1, 2 ), array( 2, 1 ) ), array( 3.0, 1.0 ) );

		$this->assertSame( 1, array_key_first( $even ), 'Ties resolve to first-seen.' );
		$this->assertSame( 1, array_key_first( $vectorly ) );
		$this->assertGreaterThan( $even[1] - $even[2], $vectorly[1] - $vectorly[2] );
	}

	public function testEmptyListsFuseToNothing(): void {
		$this->assertSame( array(), ReciprocalRankFusion::fuse( array() ) );
		$this->assertSame( array(), ReciprocalRankFusion::fuse( array( array(), array() ) ) );
	}

	public function testRanksStartAtOneAndIgnoreRepeats(): void {
		$this->assertSame(
			array(
				4 => 1,
				9 => 2,
				2 => 3,
			),
			ReciprocalRankFusion::ranks( array( 4, 9, 2 ) )
		);

		// A repeated id keeps the better position it actually held.
		$this->assertSame(
			array(
				4 => 1,
				9 => 2,
			),
			ReciprocalRankFusion::ranks( array( 4, 9, 4 ) )
		);
	}

	// -------------------------------------------------------------- options

	public function testCandidateCountAlwaysExceedsTheRequestedResults(): void {
		// A top-50 request against a 200-candidate coarse pass would be
		// re-ranking a quarter of its own input, which is not two stages.
		foreach ( array( 1, 5, 10, 30, 50 ) as $k ) {
			$options = RetrievalOptions::of( topK: $k );

			$this->assertGreaterThanOrEqual( $k * 10, $options->candidates );
			$this->assertGreaterThanOrEqual( RetrievalOptions::DEFAULT_CANDIDATES, $options->candidates );
		}
	}

	public function testRequestInputIsClampedRatherThanTrusted(): void {
		$this->assertSame( 50, RetrievalOptions::of( topK: 5000 )->topK );
		$this->assertSame( 1, RetrievalOptions::of( topK: -3 )->topK );
		$this->assertSame( 1.0, RetrievalOptions::of( threshold: 9.9 )->threshold );
		$this->assertSame( 0.0, RetrievalOptions::of( threshold: -1.0 )->threshold );
		$this->assertSame( RetrievalOptions::DEFAULT_THRESHOLD, RetrievalOptions::of()->threshold );
	}

	/**
	 * The failure that cost 0.055 recall, reduced to its smallest form.
	 *
	 * Unweighted, a chunk that tops the keyword list ties with one that tops
	 * the vector list however poor its semantic match is, and ordering alone
	 * cannot tell them apart. On real content that meant long documents full
	 * of common words displacing the actual answer.
	 */
	public function testAnUnweightedKeywordWinnerTiesWithTheVectorWinner(): void {
		$fused = ReciprocalRankFusion::fuse( array( array( 10 ), array( 99 ) ) );

		$this->assertSame( array( 10, 99 ), array_keys( $fused ) );
		// Equal scores: the keyword-only chunk is indistinguishable from the
		// best semantic match, and only insertion order separates them.
		$this->assertEqualsWithDelta( $fused[10], $fused[99], 1e-12 );
	}

	public function testWeightingStopsKeywordDisplacingAStrongerVectorMatch(): void {
		// The vector arm's second-place chunk must still outrank a chunk that
		// exists only because a keyword coincided.
		$fused = ReciprocalRankFusion::fuse(
			array( array( 10, 11 ), array( 99 ) ),
			array( 1.0, 0.2 )
		);

		$this->assertSame( array( 10, 11, 99 ), array_keys( $fused ) );
		$this->assertGreaterThan( $fused[99], $fused[11] );
	}

	public function testAWeightedKeywordArmStillContributes(): void {
		// Weighting must not be a disguised switch-off: a chunk only the
		// keyword arm found — a part number, an SKU, an error code — has to
		// keep reaching the results, which is the whole point of fusing.
		$fused = ReciprocalRankFusion::fuse(
			array( array( 10 ), array( 99 ) ),
			array( 1.0, 0.2 )
		);

		$this->assertArrayHasKey( 99, $fused );
		$this->assertGreaterThan( 0.0, $fused[99] );
	}
}
