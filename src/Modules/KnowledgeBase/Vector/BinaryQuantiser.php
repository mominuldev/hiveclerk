<?php
/**
 * One-bit-per-dimension quantisation and Hamming distance.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Vector;

use InvalidArgumentException;

/**
 * Turns a float vector into 1 bit per dimension, and compares them fast.
 *
 * This is what makes retrieval possible on shared hosting at all. A
 * 1,536-dimension float32 vector is 6,144 bytes; the same vector as one
 * bit per dimension is 192. Ten thousand of them is 1.9 MB, which fits in
 * an object cache and can be scanned in a single pass — the float32 form
 * would be 61 MB and would not survive the request's memory budget, let
 * alone its time budget.
 *
 * ## Why a sign bit is enough
 *
 * Quantising to `component > 0` throws away magnitude entirely and keeps
 * only which side of the origin each dimension falls on. That retains
 * roughly 95% of recall at the top 200 for modern embedding models,
 * because in a space of over a thousand dimensions the sign pattern alone
 * is a strong fingerprint. It is not enough to *rank* with — which is why
 * stage 2 re-scores the survivors exactly. The coarse pass only has to
 * get the right chunks into the candidate set.
 *
 * ## Why the distance calculation looks the way it does
 *
 * The naive loop — iterate bytes, look up a popcount table — is around
 * two million PHP-level operations at ten thousand chunks, and PHP-level
 * operations are the expensive kind. Two things avoid that:
 *
 * 1. `gmp_hamdist()` does the whole comparison in C when ext-gmp exists.
 * 2. Without it, `count_chars()` reduces a 192-byte string to at most 192
 *    distinct byte values *in C*, and only those are looked up. The table
 *    is then consulted once per distinct byte rather than once per byte.
 *
 * Both paths produce identical results; the test suite asserts that
 * against a reference implementation rather than assuming it.
 */
final class BinaryQuantiser {

	/**
	 * Widest vector the storage column can hold.
	 *
	 * `embedding_bits` is `VARBINARY(256)` — 256 bytes, 2,048 bits, one per
	 * dimension. The limit is a schema decision, not an arithmetic one:
	 * widening the column widens the hot scan proportionally, and the
	 * models worth using either fit or can be asked for a shorter vector.
	 */
	public const MAX_DIMENSIONS = 2048;

	/**
	 * Popcount for every byte value, built once per request.
	 *
	 * @var array<int, int>|null
	 */
	private static ?array $table = null;

	/**
	 * Pack a vector into one bit per dimension.
	 *
	 * Bit order is most-significant-first within each byte, and the final
	 * byte is zero-padded. Only consistency matters — Hamming distance is
	 * computed between two strings produced by this same function — but it
	 * has to stay stable across releases, because changing it would
	 * invalidate every vector already stored without any visible error.
	 *
	 * @param array<int, float> $vector Components.
	 * @return string Packed bits.
	 *
	 * @throws InvalidArgumentException When the vector is wider than the column.
	 */
	public static function quantise( array $vector ): string {
		$dimensions = count( $vector );

		if ( $dimensions > self::MAX_DIMENSIONS ) {
			throw new InvalidArgumentException(
				sprintf(
					'A %d-dimension vector does not fit Hiveclerk\'s quantised index, which holds %d. '
					. 'Choose an embedding model with fewer dimensions.',
					$dimensions,
					self::MAX_DIMENSIONS
				)
			);
		}

		$bits  = '';
		$byte  = 0;
		$shift = 7;

		foreach ( $vector as $component ) {
			if ( $component > 0 ) {
				$byte |= 1 << $shift;
			}

			--$shift;

			if ( $shift < 0 ) {
				$bits .= chr( $byte );
				$byte  = 0;
				$shift = 7;
			}
		}

		// A vector whose width is not a multiple of eight leaves a partly
		// filled byte. It is written with its remaining bits zero, which is
		// the same padding every vector of that width gets, so the padding
		// contributes nothing to any distance between them.
		if ( $shift < 7 ) {
			$bits .= chr( $byte );
		}

		return $bits;
	}

	/**
	 * Bytes a vector of this width occupies once quantised.
	 *
	 * @param int $dimensions Width.
	 * @return int
	 */
	public static function width( int $dimensions ): int {
		return (int) ceil( $dimensions / 8 );
	}

	/**
	 * Number of differing bits between two packed vectors.
	 *
	 * @param string $left  Packed bits.
	 * @param string $right Packed bits, same length.
	 * @return int
	 */
	public static function hamming( string $left, string $right ): int {
		return self::popcount( $left ^ $right );
	}

	/**
	 * Number of set bits in a binary string.
	 *
	 * @param string $bytes Binary string.
	 * @return int
	 */
	public static function popcount( string $bytes ): int {
		if ( '' === $bytes ) {
			return 0;
		}

		if ( self::hasGmp() ) {
			return gmp_popcount( gmp_import( $bytes ) );
		}

		$table = self::table();
		$total = 0;

		// count_chars() collapses the string to its distinct byte values and
		// their frequencies in C. A 192-byte row has at most 192 of them and
		// usually far fewer, so the PHP-level loop that follows is an order
		// of magnitude shorter than one iteration per byte.
		foreach ( count_chars( $bytes, 1 ) as $value => $frequency ) {
			$total += $table[ $value ] * $frequency;
		}

		return $total;
	}

	/**
	 * Which popcount implementation is in use.
	 *
	 * Reported in retrieval diagnostics, because it is the difference
	 * between a scan that costs single-digit milliseconds and one that
	 * costs tens of them — and it is otherwise invisible.
	 *
	 * @return string
	 */
	public static function implementation(): string {
		return self::hasGmp() ? 'gmp' : 'table';
	}

	/**
	 * Whether the GMP extension is available.
	 *
	 * @return bool
	 */
	public static function hasGmp(): bool {
		return function_exists( 'gmp_popcount' ) && function_exists( 'gmp_import' );
	}

	/**
	 * The 256-entry popcount table.
	 *
	 * @return array<int, int>
	 */
	private static function table(): array {
		if ( null !== self::$table ) {
			return self::$table;
		}

		$table = array();

		for ( $i = 0; $i < 256; $i++ ) {
			$count = 0;
			$value = $i;

			while ( $value > 0 ) {
				$count  += $value & 1;
				$value >>= 1;
			}

			$table[ $i ] = $count;
		}

		self::$table = $table;

		return $table;
	}
}
