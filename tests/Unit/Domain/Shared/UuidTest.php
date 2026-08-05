<?php
/**
 * UUID tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Domain\Shared;

use Hiveclerk\Domain\Shared\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass( Uuid::class )]
final class UuidTest extends TestCase {

	public function testGeneratesAValidV4Uuid(): void {
		$uuid = Uuid::generate();

		$this->assertTrue( Uuid::isValid( $uuid->value ) );
		$this->assertSame( 36, strlen( $uuid->value ) );
	}

	public function testGeneratedUuidsAreUnique(): void {
		$seen = array();

		for ( $i = 0; $i < 500; $i++ ) {
			$seen[ Uuid::generate()->value ] = true;
		}

		$this->assertCount( 500, $seen );
	}

	public function testVersionAndVariantNibblesAreCorrect(): void {
		// A wrong version nibble is the classic hand-rolled-UUID bug: the
		// string still looks right but is not a valid v4.
		for ( $i = 0; $i < 50; $i++ ) {
			$value = Uuid::generate()->value;

			$this->assertSame( '4', $value[14], 'Version nibble must be 4.' );
			$this->assertContains( $value[19], array( '8', '9', 'a', 'b' ), 'Variant nibble must be RFC 4122.' );
		}
	}

	#[DataProvider( 'invalidValues' )]
	public function testRejectsInvalidValues( string $value ): void {
		$this->expectException( InvalidArgumentException::class );

		new Uuid( $value );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function invalidValues(): array {
		return array(
			'empty'         => array( '' ),
			'not a uuid'    => array( 'hello' ),
			'wrong version' => array( '3f2504e0-4f89-31d3-9a0c-0305e82c3301' ),
			'wrong variant' => array( '3f2504e0-4f89-41d3-1a0c-0305e82c3301' ),
			'too short'     => array( '3f2504e0-4f89-41d3-9a0c-0305e82c33' ),
			'no hyphens'    => array( '3f2504e04f8941d39a0c0305e82c3301' ),
			'sql injection' => array( "' OR 1=1 --" ),
		);
	}

	public function testStringConversion(): void {
		$uuid = Uuid::generate();

		$this->assertSame( $uuid->value, (string) $uuid );
	}
}
