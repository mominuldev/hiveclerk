<?php
/**
 * Pagination value object.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Shared;

/**
 * A validated page request.
 */
final readonly class Pagination {

	public const MAX_PER_PAGE = 100;

	/**
	 * Construct, clamping both values into a usable range.
	 *
	 * @param int $page     One-based page number.
	 * @param int $perPage  Items per page.
	 */
	public function __construct(
		public int $page = 1,
		public int $perPage = 25
	) {
	}

	/**
	 * Build from untrusted request input.
	 *
	 * Clamping rather than rejecting: a caller asking for per_page=5000 gets
	 * 100 rather than a 422, because there is no security benefit to the
	 * error and it costs the client a round trip.
	 *
	 * @param mixed $page    Raw page value.
	 * @param mixed $perPage Raw per-page value.
	 * @return self
	 */
	public static function fromRequest( mixed $page, mixed $perPage ): self {
		$pageNumber = is_numeric( $page ) ? (int) $page : 1;
		$size       = is_numeric( $perPage ) ? (int) $perPage : 25;

		return new self(
			max( 1, $pageNumber ),
			min( self::MAX_PER_PAGE, max( 1, $size ) )
		);
	}

	/**
	 * SQL offset.
	 *
	 * @return int
	 */
	public function offset(): int {
		return ( $this->page - 1 ) * $this->perPage;
	}

	/**
	 * Total pages for a row count.
	 *
	 * @param int $total Total rows.
	 * @return int
	 */
	public function totalPages( int $total ): int {
		if ( $total <= 0 ) {
			return 0;
		}

		return (int) ceil( $total / $this->perPage );
	}
}
