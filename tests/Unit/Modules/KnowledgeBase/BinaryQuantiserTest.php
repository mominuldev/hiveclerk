<?php
/**
 * Quantiser tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\KnowledgeBase;

use Hiveclerk\Domain\Knowledge\Embedding;
use Hiveclerk\Modules\KnowledgeBase\Vector\BinaryQuantiser;
use Hiveclerk\Modules\KnowledgeBase\Vector\CosineCalculator;
use Hiveclerk\Modules\KnowledgeBase\Vector\VectorCodec;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The coarse pass decides which chunks the exact pass ever sees, so an
 * error here is invisible: retrieval keeps returning results, they are
 * just quietly the wrong ones. These tests check the properties that have
 * to hold for every input rather than one worked example, and check the
 * optimised popcount against a naive reference rather than against
 * itself.
 *
 * @internal
 */
#[CoversClass( BinaryQuantiser::class )]
#[CoversClass( CosineCalculator::class )]
#[CoversClass( VectorCodec::class )]
#[CoversClass( Embedding::class )]
final class BinaryQuantiserTest extends TestCase {

	// ------------------------------------------------------------ quantising

	public function testEachDimensionBecomesOneBit(): void {
		$this->assertSame( 1, strlen( BinaryQuantiser::quantise( array_fill( 0, 8, 1.0 ) ) ) );
		$this->assertSame( 192, strlen( BinaryQuantiser::quantise( array_fill( 0, 1536, 1.0 ) ) ) );
	}

	public function testPositiveComponentsSetBitsAndOthersDoNot(): void {
		// 1000_0000: only the first component is positive, and the bit order
		// is most-significant-first within the byte.
		$this->assertSame(
			chr( 0b10000000 ),
			BinaryQuantiser::quantise( array( 0.5, -0.5, -0.1, 0.0, -1.0, -2.0, -0.3, -0.7 ) )
		);

		// Zero is not positive. It falls on the same side as a negative,
		// which is arbitrary but has to be consistent — a vector quantised
		// twice must produce identical bytes or nothing matches itself.
		$this->assertSame(
			chr( 0b00000000 ),
			BinaryQuantiser::quantise( array_fill( 0, 8, 0.0 ) )
		);
	}

	public function testAPartialFinalByteIsZeroPadded(): void {
		// Ten dimensions occupy two bytes, of which six bits are padding.
		$bits = BinaryQuantiser::quantise( array( 1, 1, 1, 1, 1, 1, 1, 1, 1, 1 ) );

		$this->assertSame( 2, strlen( $bits ) );
		$this->assertSame( chr( 0b11000000 ), $bits[1] );
	}

	public function testAVectorTooWideForTheColumnIsRefused(): void {
		$this->expectException( InvalidArgumentException::class );

		BinaryQuantiser::quantise( array_fill( 0, BinaryQuantiser::MAX_DIMENSIONS + 1, 1.0 ) );
	}

	public function testWidthMatchesTheBytesProduced(): void {
		foreach ( array( 1, 7, 8, 9, 768, 1536, 2048 ) as $dimensions ) {
			$this->assertSame(
				BinaryQuantiser::width( $dimensions ),
				strlen( BinaryQuantiser::quantise( array_fill( 0, $dimensions, 1.0 ) ) ),
				sprintf( 'Width disagrees with the packed length at %d dimensions.', $dimensions )
			);
		}
	}

	// ------------------------------------------------------------- popcount

	public function testPopcountAgreesWithANaiveReferenceAcrossEveryByte(): void {
		// Every byte value, so the table and the GMP path are both checked
		// against arithmetic rather than against each other.
		for ( $value = 0; $value < 256; $value++ ) {
			$this->assertSame(
				substr_count( decbin( $value ), '1' ),
				BinaryQuantiser::popcount( chr( $value ) ),
				sprintf( 'Popcount disagrees for byte %d.', $value )
			);
		}
	}

	public function testPopcountHandlesLeadingZeroBytes(): void {
		// The GMP path imports the string as an integer, which drops leading
		// zeroes. It must not drop the count with them.
		$this->assertSame( 1, BinaryQuantiser::popcount( "\x00\x00\x01" ) );
		$this->assertSame( 0, BinaryQuantiser::popcount( "\x00\x00\x00" ) );
		$this->assertSame( 0, BinaryQuantiser::popcount( '' ) );
	}

	public function testHammingIsZeroForIdenticalVectorsAndMaximalForInverted(): void {
		$vector   = $this->vector( 128, 1 );
		$inverted = array_map( static fn ( float $c ): float => -$c, $vector );

		$a = BinaryQuantiser::quantise( $vector );
		$b = BinaryQuantiser::quantise( $inverted );

		$this->assertSame( 0, BinaryQuantiser::hamming( $a, $a ) );
		$this->assertSame( 128, BinaryQuantiser::hamming( $a, $b ) );
	}

	public function testHammingIsSymmetric(): void {
		$a = BinaryQuantiser::quantise( $this->vector( 64, 2 ) );
		$b = BinaryQuantiser::quantise( $this->vector( 64, 3 ) );

		$this->assertSame(
			BinaryQuantiser::hamming( $a, $b ),
			BinaryQuantiser::hamming( $b, $a )
		);
	}

	// ---------------------------------------------------------------- codec

	public function testPackedVectorsRoundTripWithinFloat32Precision(): void {
		$vector = $this->vector( 256, 7 );
		$back   = VectorCodec::unpack( VectorCodec::pack( $vector ) );

		$this->assertCount( count( $vector ), $back );

		foreach ( $vector as $index => $component ) {
			// float32 keeps about seven significant digits. Anything looser
			// than 1e-6 here would not be testing the encoding at all.
			$this->assertEqualsWithDelta( $component, $back[ $index ], 1e-6 );
		}
	}

	public function testPackedVectorsAreZeroIndexedLists(): void {
		// unpack() returns a 1-indexed array. If that leaked out, every dot
		// product would be computed against a vector shifted by one
		// dimension and would still produce a plausible number.
		$back = VectorCodec::unpack( VectorCodec::pack( array( 1.0, 2.0, 3.0 ) ) );

		$this->assertSame( array( 0, 1, 2 ), array_keys( $back ) );
		$this->assertSame( 3, VectorCodec::dimensions( VectorCodec::pack( array( 1.0, 2.0, 3.0 ) ) ) );
	}

	// --------------------------------------------------------------- cosine

	public function testCosineIsOneForItselfAndMinusOneForItsOpposite(): void {
		$vector = $this->vector( 64, 11 );
		$norm   = CosineCalculator::norm( $vector );

		$this->assertEqualsWithDelta(
			1.0,
			CosineCalculator::score( $vector, $norm, VectorCodec::pack( $vector ) ),
			1e-5
		);

		$opposite = array_map( static fn ( float $c ): float => -$c, $vector );

		$this->assertEqualsWithDelta(
			-1.0,
			CosineCalculator::score( $vector, $norm, VectorCodec::pack( $opposite ) ),
			1e-5
		);
	}

	public function testCosineRefusesVectorsOfADifferentWidth(): void {
		$query = $this->vector( 64, 13 );

		// Two models, two widths, no meaningful comparison. Zero rather
		// than a number computed from whatever overlapped.
		$this->assertSame(
			0.0,
			CosineCalculator::score(
				$query,
				CosineCalculator::norm( $query ),
				VectorCodec::pack( $this->vector( 32, 13 ) )
			)
		);
	}

	public function testAZeroLengthVectorScoresZeroRatherThanDividingByZero(): void {
		$zero = array_fill( 0, 16, 0.0 );

		$this->assertSame(
			0.0,
			CosineCalculator::score( $zero, CosineCalculator::norm( $zero ), VectorCodec::pack( $zero ) )
		);

		$this->assertSame( 0.0, ( new Embedding( $zero ) )->cosine( new Embedding( $zero ) ) );
	}

	// -------------------------------------------------------------- recall

	public function testTheCoarsePassKeepsTheNearestVectorInItsCandidateSet(): void {
		/*
		 * The property the whole two-stage design rests on: quantisation is
		 * allowed to get the *ordering* wrong, because stage 2 re-ranks
		 * exactly — but it must not drop the true nearest neighbour out of
		 * the candidate set, because stage 2 never sees what stage 1
		 * discarded.
		 */
		$query     = $this->vector( 256, 42 );
		$queryBits = BinaryQuantiser::quantise( $query );
		$queryNorm = CosineCalculator::norm( $query );

		$corpus = array();

		for ( $i = 0; $i < 400; $i++ ) {
			$corpus[ $i ] = $this->vector( 256, 1000 + $i );
		}

		// One vector deliberately close to the query: the same direction
		// with a little noise, which is what a genuinely relevant chunk
		// looks like in embedding space.
		$corpus[123] = array_map(
			static fn ( float $c, int $i ): float => $c + ( ( ( $i * 37 ) % 11 ) - 5 ) / 500,
			$query,
			array_keys( $query )
		);

		$exact = array();
		$rough = array();

		foreach ( $corpus as $id => $vector ) {
			$exact[ $id ] = CosineCalculator::score( $query, $queryNorm, VectorCodec::pack( $vector ) );
			$rough[ $id ] = BinaryQuantiser::hamming( $queryBits, BinaryQuantiser::quantise( $vector ) );
		}

		arsort( $exact );
		asort( $rough );

		$best       = array_key_first( $exact );
		$candidates = array_slice( array_keys( $rough ), 0, 200 );

		$this->assertSame( 123, $best, 'The planted neighbour should be the exact nearest.' );
		$this->assertContains(
			$best,
			$candidates,
			'The coarse pass dropped the true nearest neighbour, which stage 2 can never recover.'
		);
	}

	/**
	 * A deterministic pseudo-random unit-ish vector.
	 *
	 * Seeded arithmetic rather than mt_rand(): a recall test that passes on
	 * one run and fails on the next is worse than no test, because the
	 * failure gets attributed to flakiness rather than to the change that
	 * caused it.
	 *
	 * @param int $dimensions Width.
	 * @param int $seed       Seed.
	 * @return array<int, float>
	 */
	private function vector( int $dimensions, int $seed ): array {
		$vector = array();
		$state  = $seed * 2654435761 % 2147483647;

		for ( $i = 0; $i < $dimensions; $i++ ) {
			$state    = ( $state * 1103515245 + 12345 ) % 2147483647;
			$vector[] = ( $state / 2147483647 ) - 0.5;
		}

		return $vector;
	}
}
