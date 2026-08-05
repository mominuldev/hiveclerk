<?php
/**
 * CSV writing.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Support;

/**
 * The one place this product turns values into CSV cells.
 *
 * Promoted here when the second exporter arrived. Two copies of formula
 * neutralisation is one copy that gets fixed and one that does not, and
 * the one that does not is a working attack on the customer's own
 * machine.
 */
final class Csv {

	/**
	 * One CSV line, terminated by nothing.
	 *
	 * @param array<int, string> $values Cell values.
	 * @return string
	 */
	public static function line( array $values ): string {
		return implode( ',', array_map( array( self::class, 'cell' ), $values ) );
	}

	/**
	 * One quoted CSV cell.
	 *
	 * ## Formula injection
	 *
	 * A cell starting `=`, `+`, `-` or `@` is executed as a formula when
	 * the file is opened in Excel, Sheets or Numbers. Strings reaching an
	 * export in this product came from website visitors, so
	 * `=HYPERLINK("http://evil","click")` in a company-name field is a
	 * working attack on whoever opens the export — carried out by our own
	 * file, on their machine, with their spreadsheet's permissions.
	 *
	 * The fix is a leading apostrophe, which every spreadsheet reads as
	 * "this is text". The visible cost is one odd-looking cell in the rare
	 * case a value legitimately starts with a minus sign.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function cell( string $value ): string {
		$value = str_replace( array( "\r\n", "\r", "\n" ), ' ', $value );

		if ( '' !== $value && str_contains( "=+-@\t", $value[0] ) ) {
			$value = "'" . $value;
		}

		return '"' . str_replace( '"', '""', $value ) . '"';
	}
}
