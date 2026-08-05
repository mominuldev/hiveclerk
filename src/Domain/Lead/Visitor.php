<?php
/**
 * Visitor entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

use DateTimeImmutable;
use Hiveclerk\Domain\Shared\Uuid;

/**
 * Somebody on the site who has not said who they are (FR-LED-07).
 *
 * There is nothing identifying here on purpose. The IP is a salted hash,
 * the fingerprint is derived rather than collected, and none of it is
 * ever shown to an operator. What the record exists for is arithmetic:
 * "visited /pricing twice" is a scoring rule, and a rule needs something
 * to count.
 */
final class Visitor {

	/**
	 * Construct.
	 *
	 * @param int|null               $id           Storage id.
	 * @param Uuid                   $uuid         Public identifier, held by the widget.
	 * @param int|null               $leadId       Lead, once stitched.
	 * @param int|null               $wpUserId     Signed-in account, when there is one.
	 * @param string|null            $fingerprint  Derived, coarse device signature.
	 * @param string|null            $ipHash       Salted hash of the address.
	 * @param string|null            $userAgent    Reported agent string, truncated.
	 * @param string|null            $country      Two-letter code, when the host provides one.
	 * @param string|null            $language     Reported locale.
	 * @param int                    $pageViews    Pages seen across all sessions.
	 * @param int                    $sessionCount Sessions opened.
	 * @param array<string, mixed>   $metadata     Page-view tallies and telemetry.
	 * @param DateTimeImmutable|null $firstSeenAt  First sighting, UTC.
	 * @param DateTimeImmutable|null $lastSeenAt   Most recent sighting, UTC.
	 */
	public function __construct(
		public ?int $id,
		public Uuid $uuid,
		public ?int $leadId = null,
		public ?int $wpUserId = null,
		public ?string $fingerprint = null,
		public ?string $ipHash = null,
		public ?string $userAgent = null,
		public ?string $country = null,
		public ?string $language = null,
		public int $pageViews = 0,
		public int $sessionCount = 1,
		public array $metadata = array(),
		public ?DateTimeImmutable $firstSeenAt = null,
		public ?DateTimeImmutable $lastSeenAt = null,
	) {
	}

	/**
	 * How many times this visitor has seen a given path.
	 *
	 * @param string $path Normalised path.
	 * @return int
	 */
	public function viewsOf( string $path ): int {
		$pages = $this->metadata['pages'] ?? array();

		if ( ! is_array( $pages ) ) {
			return 0;
		}

		$count = $pages[ $path ] ?? 0;

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Every path this visitor has seen, with its count.
	 *
	 * @return array<string, int>
	 */
	public function pageTally(): array {
		$pages = $this->metadata['pages'] ?? array();

		if ( ! is_array( $pages ) ) {
			return array();
		}

		$tally = array();

		foreach ( $pages as $path => $count ) {
			if ( is_string( $path ) && is_numeric( $count ) ) {
				$tally[ $path ] = (int) $count;
			}
		}

		return $tally;
	}

	/**
	 * Record one page view.
	 *
	 * The tally is capped. It lives in a JSON column read on every
	 * scoring run, and a crawler or a single-page app firing on every
	 * route change would otherwise grow one row without bound until it
	 * is the slowest read in the product.
	 *
	 * @param string $path Normalised path.
	 * @param int    $cap  Distinct paths retained.
	 * @return void
	 */
	public function recordView( string $path, int $cap = 50 ): void {
		$pages = $this->pageTally();

		if ( ! isset( $pages[ $path ] ) && count( $pages ) >= $cap ) {
			++$this->pageViews;

			return;
		}

		$pages[ $path ] = ( $pages[ $path ] ?? 0 ) + 1;

		$this->metadata['pages'] = $pages;

		++$this->pageViews;
	}
}
