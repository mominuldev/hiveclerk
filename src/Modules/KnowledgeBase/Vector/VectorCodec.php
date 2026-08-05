<?php
/**
 * Float vector encoding.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\KnowledgeBase\Vector;

use Hiveclerk\Domain\Knowledge\Embedding;
use Hiveclerk\Domain\Knowledge\StoredEmbedding;

/**
 * Moves vectors between float arrays and packed bytes.
 *
 * `pack('g*')` — float32, explicitly little-endian — rather than `'f*'`,
 * which is machine byte order. The difference never shows up in
 * development and shows up permanently in a database restored onto a
 * machine of the other endianness, where every stored vector reads as
 * noise and retrieval degrades without erroring.
 *
 * float32 rather than PHP's native float64 halves the largest table in
 * the schema at a precision loss of roughly seven significant digits,
 * which is three orders of magnitude finer than the differences cosine
 * similarity resolves between chunks.
 */
final class VectorCodec {

	/**
	 * Bytes per component.
	 */
	public const FLOAT_BYTES = 4;

	/**
	 * Pack a float vector.
	 *
	 * @param array<int, float> $vector Components.
	 * @return string
	 */
	public static function pack( array $vector ): string {
		if ( array() === $vector ) {
			return '';
		}

		return pack( 'g*', ...array_values( $vector ) );
	}

	/**
	 * Unpack a float vector.
	 *
	 * @param string $blob Packed float32.
	 * @return array<int, float>
	 */
	public static function unpack( string $blob ): array {
		if ( '' === $blob ) {
			return array();
		}

		$values = unpack( 'g*', $blob );

		if ( false === $values ) {
			return array();
		}

		// unpack() returns a 1-indexed array; every caller treats a vector
		// as a list, and an off-by-one in the index would silently shift
		// one operand of every dot product by a dimension.
		return array_values( array_map( 'floatval', $values ) );
	}

	/**
	 * How many components a packed blob holds.
	 *
	 * @param string $blob Packed float32.
	 * @return int
	 */
	public static function dimensions( string $blob ): int {
		return intdiv( strlen( $blob ), self::FLOAT_BYTES );
	}

	/**
	 * Prepare a vector for storage.
	 *
	 * @param Embedding $embedding Vector.
	 * @param int       $chunkId   Owning chunk.
	 * @param int       $sourceId  Owning source.
	 * @return StoredEmbedding
	 */
	public static function encode( Embedding $embedding, int $chunkId, int $sourceId ): StoredEmbedding {
		return new StoredEmbedding(
			chunkId: $chunkId,
			sourceId: $sourceId,
			provider: $embedding->provider,
			model: $embedding->model,
			dimensions: $embedding->dimensions(),
			f32: self::pack( $embedding->vector ),
			bits: BinaryQuantiser::quantise( $embedding->vector ),
			norm: $embedding->norm()
		);
	}

	/**
	 * Read a stored row back as a vector.
	 *
	 * @param StoredEmbedding $stored Row.
	 * @return Embedding
	 */
	public static function decode( StoredEmbedding $stored ): Embedding {
		return new Embedding(
			self::unpack( $stored->f32 ),
			$stored->provider,
			$stored->model
		);
	}
}
