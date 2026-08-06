<?php
/**
 * Quantised matrix tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Domain\Knowledge;

use Hiveclerk\Domain\Knowledge\EmbeddingMatrix;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The matrix is cached a source at a time and joined here.
 *
 * Cached whole, its size was the size of everything a clerk was pointed
 * at — over Memcached's one-megabyte item limit at about five thousand
 * chunks, over the transient ceiling at sixteen thousand, and past either
 * of those a failed write looks exactly like a cache miss, so every
 * visitor message rebuilt it from a full table scan.
 *
 * Rows are fixed width, so the join is concatenation. The part worth
 * testing is what happens when a shard does not fit that assumption:
 * appending it would slide every row after it by a few bytes and quietly
 * corrupt every comparison, which is worse than losing one source.
 *
 * @internal
 */
#[CoversClass( EmbeddingMatrix::class )]
final class EmbeddingMatrixTest extends TestCase {

	public function testShardsJoinInOrderAndKeepTheirIds(): void {
		$joined = EmbeddingMatrix::concat(
			array(
				new EmbeddingMatrix( array( 1, 2 ), 'aabb', 2 ),
				new EmbeddingMatrix( array( 3 ), 'cc', 2 ),
			)
		);

		self::assertSame( array( 1, 2, 3 ), $joined->ids );
		self::assertSame( 'aabbcc', $joined->bits );
		self::assertSame( 2, $joined->width );
		self::assertTrue( $joined->isConsistent() );
	}

	public function testAnEmptyShardContributesNothing(): void {
		$joined = EmbeddingMatrix::concat(
			array(
				EmbeddingMatrix::empty( 2 ),
				new EmbeddingMatrix( array( 7 ), 'zz', 2 ),
				EmbeddingMatrix::empty(),
			)
		);

		self::assertSame( array( 7 ), $joined->ids );
		self::assertSame( 2, $joined->width );
		self::assertTrue( $joined->isConsistent() );
	}

	/**
	 * A source indexed at a different dimension count is dropped rather
	 * than appended. Its rows are the wrong length, so every row after it
	 * would be read from the wrong offset.
	 */
	public function testAShardOfADifferentWidthIsLeftOutRatherThanMisaligned(): void {
		$joined = EmbeddingMatrix::concat(
			array(
				new EmbeddingMatrix( array( 1 ), 'aa', 2 ),
				new EmbeddingMatrix( array( 2 ), 'bbbb', 4 ),
				new EmbeddingMatrix( array( 3 ), 'cc', 2 ),
			)
		);

		self::assertSame( array( 1, 3 ), $joined->ids );
		self::assertSame( 'aacc', $joined->bits );
		self::assertTrue( $joined->isConsistent(), 'the result must still scan correctly' );
	}

	public function testJoiningNothingIsAnEmptyMatrix(): void {
		$joined = EmbeddingMatrix::concat( array() );

		self::assertTrue( $joined->isEmpty() );
		self::assertTrue( $joined->isConsistent() );
	}
}
