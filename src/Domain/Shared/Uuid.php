<?php
/**
 * UUID value object.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Shared;

use InvalidArgumentException;

/**
 * A RFC 4122 version 4 UUID.
 *
 * Every record exposed over HTTP is addressed by UUID rather than by its
 * auto-increment id, so identifiers cannot be enumerated by a visitor
 * counting upwards.
 */
final readonly class Uuid {

	private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

	/**
	 * Construct from a validated string.
	 *
	 * @param string $value Canonical UUID string.
	 *
	 * @throws InvalidArgumentException When the value is not a v4 UUID.
	 */
	public function __construct( public string $value ) {
		if ( 1 !== preg_match( self::PATTERN, $value ) ) {
			throw new InvalidArgumentException(
				sprintf( '"%s" is not a version 4 UUID.', $value )
			);
		}
	}

	/**
	 * Generate a new random UUID.
	 *
	 * @return self
	 */
	public static function generate(): self {
		$bytes = random_bytes( 16 );

		// Set version to 4 and the variant to RFC 4122.
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return new self( vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) ) );
	}

	/**
	 * Whether a string is a valid v4 UUID.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	public static function isValid( string $value ): bool {
		return 1 === preg_match( self::PATTERN, $value );
	}

	/**
	 * String form.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return $this->value;
	}
}
