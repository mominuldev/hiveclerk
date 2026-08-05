<?php
/**
 * Pagination tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Domain\Shared;

use Hiveclerk\Domain\Shared\Pagination;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass( Pagination::class )]
final class PaginationTest extends TestCase {

	public function testOffsetIsZeroOnTheFirstPage(): void {
		$this->assertSame( 0, ( new Pagination( 1, 25 ) )->offset() );
	}

	public function testOffsetAdvancesByPageSize(): void {
		$this->assertSame( 50, ( new Pagination( 3, 25 ) )->offset() );
	}

	public function testClampsPerPageToTheCeiling(): void {
		$pagination = Pagination::fromRequest( 1, 5000 );

		$this->assertSame( Pagination::MAX_PER_PAGE, $pagination->perPage );
	}

	public function testClampsNonsensicalValuesRatherThanFailing(): void {
		// Clamping beats rejecting here: there is no security benefit to a
		// 422 and it costs the client a round trip.
		$pagination = Pagination::fromRequest( -5, 0 );

		$this->assertSame( 1, $pagination->page );
		$this->assertSame( 1, $pagination->perPage );
	}

	public function testFallsBackToDefaultsForNonNumericInput(): void {
		$pagination = Pagination::fromRequest( 'abc', null );

		$this->assertSame( 1, $pagination->page );
		$this->assertSame( 25, $pagination->perPage );
	}

	public function testTotalPagesRoundsUp(): void {
		$pagination = new Pagination( 1, 25 );

		$this->assertSame( 4, $pagination->totalPages( 92 ) );
		$this->assertSame( 1, $pagination->totalPages( 1 ) );
		$this->assertSame( 0, $pagination->totalPages( 0 ) );
	}
}
