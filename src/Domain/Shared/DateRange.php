<?php
/**
 * An inclusive span of UTC days.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Shared;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Two dates and the arithmetic every report needs between them.
 *
 * Both ends are inclusive, which is the only reading a customer has when
 * they pick "1–30 June" out of a date picker. An exclusive end would make
 * every report quietly one day short, and the error is invisible: the
 * numbers still look like numbers.
 *
 * Days here are UTC calendar days because that is what the `date` column
 * on the rollup table holds. A site in Auckland sees its evening filed
 * under the following day, which is stated on the analytics screen rather
 * than corrected — a per-site timezone offset would make today's live
 * figures and yesterday's rolled-up figures disagree at the boundary, and
 * a dashboard that contradicts itself is worse than one that is honest
 * about its clock.
 */
final class DateRange {

	/**
	 * Longest span any report will assemble.
	 *
	 * Bounded because every query behind a report scans an index range,
	 * and an unbounded span is the request that turns a fast query into a
	 * table scan on a site with three years of history.
	 */
	public const MAX_DAYS = 366;

	/**
	 * Construct.
	 *
	 * @param string $from Inclusive start, Y-m-d.
	 * @param string $to   Inclusive end, Y-m-d.
	 *
	 * @throws InvalidArgumentException When the dates are malformed or inverted.
	 */
	public function __construct(
		public readonly string $from,
		public readonly string $to
	) {
		if ( ! self::isDate( $from ) || ! self::isDate( $to ) ) {
			throw new InvalidArgumentException( 'A date range needs two Y-m-d dates.' );
		}

		if ( $from > $to ) {
			throw new InvalidArgumentException( 'A date range cannot end before it starts.' );
		}
	}

	/**
	 * Whether a string is a real calendar date in Y-m-d form.
	 *
	 * Round-tripped through the parser rather than pattern-matched, so
	 * 2026-02-30 is rejected rather than silently becoming 2 March.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	public static function isDate( string $value ): bool {
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );

		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $value;
	}

	/**
	 * The last N days ending today, inclusive of both ends.
	 *
	 * @param DateTimeImmutable $today Reference day.
	 * @param int               $days  Length, at least 1.
	 * @return self
	 */
	public static function lastDays( DateTimeImmutable $today, int $days ): self {
		$days = max( 1, min( self::MAX_DAYS, $days ) );
		$end  = $today->setTimezone( new DateTimeZone( 'UTC' ) );

		return new self(
			$end->modify( sprintf( '-%d days', $days - 1 ) )->format( 'Y-m-d' ),
			$end->format( 'Y-m-d' )
		);
	}

	/**
	 * Number of days covered, both ends counted.
	 *
	 * @return int
	 */
	public function days(): int {
		$start = new DateTimeImmutable( $this->from, new DateTimeZone( 'UTC' ) );
		$end   = new DateTimeImmutable( $this->to, new DateTimeZone( 'UTC' ) );

		return (int) $start->diff( $end )->days + 1;
	}

	/**
	 * The equally long span immediately before this one.
	 *
	 * FR-ANL-06 compares a range against "the previous period", and the
	 * only comparison that means anything is one of the same length. A
	 * fixed "previous 30 days" against a 7-day selection would report a
	 * fall every time.
	 *
	 * @return self
	 */
	public function previous(): self {
		$days  = $this->days();
		$start = new DateTimeImmutable( $this->from, new DateTimeZone( 'UTC' ) );

		return new self(
			$start->modify( sprintf( '-%d days', $days ) )->format( 'Y-m-d' ),
			$start->modify( '-1 day' )->format( 'Y-m-d' )
		);
	}

	/**
	 * Whether a date falls inside this range.
	 *
	 * @param string $date Y-m-d.
	 * @return bool
	 */
	public function contains( string $date ): bool {
		return $date >= $this->from && $date <= $this->to;
	}

	/**
	 * Every day in the range, oldest first.
	 *
	 * Reports render a point per day including the days nothing happened.
	 * A series built only from rows that exist draws a line straight
	 * across a quiet week, which reads as steady traffic rather than none.
	 *
	 * @return array<int, string>
	 */
	public function eachDay(): array {
		$days   = array();
		$cursor = new DateTimeImmutable( $this->from, new DateTimeZone( 'UTC' ) );

		while ( $cursor->format( 'Y-m-d' ) <= $this->to ) {
			$days[] = $cursor->format( 'Y-m-d' );
			$cursor = $cursor->modify( '+1 day' );
		}

		return $days;
	}

	/**
	 * Start of the first day as a MySQL DATETIME.
	 *
	 * @return string
	 */
	public function startsAt(): string {
		return $this->from . ' 00:00:00';
	}

	/**
	 * End of the last day as a MySQL DATETIME.
	 *
	 * @return string
	 */
	public function endsAt(): string {
		return $this->to . ' 23:59:59';
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, string>
	 */
	public function toArray(): array {
		return array(
			'from' => $this->from,
			'to'   => $this->to,
		);
	}
}
