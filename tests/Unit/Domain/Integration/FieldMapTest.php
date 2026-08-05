<?php
/**
 * Field map tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Domain\Integration;

use Hiveclerk\Domain\Integration\FieldMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What may be mapped, and what may not.
 *
 * A mapping is read back out of a JSON column by code that builds HTTP
 * requests from it, so the guarantee worth protecting is that nothing
 * unrecognised survives a round trip through storage.
 *
 * @internal
 */
#[CoversClass( FieldMap::class )]
final class FieldMapTest extends TestCase {

	public function test_it_keeps_known_sources(): void {
		$map = FieldMap::fromArray(
			array(
				'email'      => 'email',
				'first_name' => 'firstname',
			)
		);

		$this->assertSame( 'firstname', $map->target( 'first_name' ) );
	}

	public function test_it_drops_a_source_no_lead_has(): void {
		$map = FieldMap::fromArray( array( 'salary' => 'annual_salary' ) );

		$this->assertTrue( $map->isEmpty() );
	}

	public function test_it_keeps_qualification_answers_by_prefix(): void {
		// Answer keys are whatever the customer configured on a clerk this
		// morning, so they cannot be enumerated — only recognised.
		$map = FieldMap::fromArray( array( 'answer:budget' => 'hvc_budget' ) );

		$this->assertSame( 'hvc_budget', $map->target( 'answer:budget' ) );
	}

	public function test_a_bare_answer_prefix_is_not_a_source(): void {
		$this->assertFalse( FieldMap::isKnownSource( 'answer:' ) );
	}

	public function test_it_drops_a_blank_target(): void {
		// "Not mapped" in the UI submits an empty string, and storing it
		// would produce a payload key pointing at nothing.
		$map = FieldMap::fromArray(
			array(
				'email'   => 'email',
				'company' => '   ',
			)
		);

		$this->assertNull( $map->target( 'company' ) );
	}

	public function test_it_drops_a_non_string_target(): void {
		$map = FieldMap::fromArray( array( 'email' => array( 'email' ) ) );

		$this->assertTrue( $map->isEmpty() );
	}

	public function test_with_forces_a_pair_over_whatever_was_submitted(): void {
		// The email row is locked. A client that skipped the form and PUT a
		// mapping without it would otherwise produce a connection whose
		// every push fails on a contact with no identity.
		$map = FieldMap::fromArray( array( 'email' => 'company_name' ) )
			->with( 'email', 'email' );

		$this->assertSame( 'email', $map->target( 'email' ) );
	}

	public function test_it_trims_a_target(): void {
		$map = FieldMap::fromArray( array( 'email' => '  email  ' ) );

		$this->assertSame( 'email', $map->target( 'email' ) );
	}
}
