<?php
/**
 * Pricing tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Ai;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Ai\Pricing;
use Hiveclerk\Ai\PricingTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass( PricingTable::class )]
#[CoversClass( Pricing::class )]
final class PricingTableTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// The table is filterable; unless a test says otherwise the filter
		// passes the value straight through.
		Functions\when( 'apply_filters' )->alias(
			static fn ( string $tag, mixed $value ): mixed => $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testFindsAnExactModel(): void {
		$table = new PricingTable();

		$pricing = $table->for( 'openai', 'gpt-5-mini' );

		$this->assertNotNull( $pricing );
		$this->assertSame( 0.25, $pricing->inputPerMillion );
	}

	public function testMatchesADatedModelIdByItsFamily(): void {
		$table = new PricingTable();

		$pricing = $table->for( 'anthropic', 'claude-sonnet-4-5-20250929' );

		$this->assertNotNull( $pricing );
		$this->assertSame( 3.00, $pricing->inputPerMillion );
	}

	public function testPrefersTheLongestFamilyMatch(): void {
		$table = new PricingTable();

		// Both "gpt-5" and "gpt-5-mini" are prefixes of this id. The more
		// specific one is four times cheaper, so picking the shorter match
		// would overstate spend on every call.
		$pricing = $table->for( 'openai', 'gpt-5-mini-2026-01-14' );

		$this->assertNotNull( $pricing );
		$this->assertSame( 0.25, $pricing->inputPerMillion );
	}

	public function testDoesNotLeakPricesAcrossProviders(): void {
		$table = new PricingTable();

		$this->assertNull( $table->for( 'anthropic', 'gpt-5' ) );
	}

	public function testReturnsNullForAnUnknownModel(): void {
		$table = new PricingTable();

		$this->assertNull( $table->for( 'openai', 'some-private-finetune' ) );
		$this->assertNull( $table->cost( 'openai', 'some-private-finetune', 1000, 500 ) );
	}

	public function testOpenRouterIsPricedByItsAdapterNotTheTable(): void {
		$table = new PricingTable();

		$this->assertNull( $table->for( 'openrouter', 'anthropic/claude-sonnet-4.5' ) );
	}

	public function testCalculatesCostPerMillionTokens(): void {
		$table = new PricingTable();

		// 1M in at $3 plus 0.5M out at $15 is $3.00 + $7.50.
		$cost = $table->cost( 'anthropic', 'claude-sonnet-4', 1_000_000, 500_000 );

		$this->assertSame( 10.5, $cost );
	}

	public function testRoundsToTheStoredPrecision(): void {
		$pricing = new Pricing( 3.00, 15.00 );

		// 1 input token at $3/M is 0.000003, which the DECIMAL(12,6)
		// column can hold exactly.
		$this->assertSame( 0.000003, $pricing->cost( 1 ) );
	}

	public function testAFilterCanOverrideAPublishedPrice(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value ): mixed {
				if ( 'hiveclerk/pricing' === $tag && is_array( $value ) ) {
					$value['openai:gpt-5'] = new Pricing( 0.50, 1.00 );
				}

				return $value;
			}
		);

		$table = new PricingTable();

		$this->assertSame( 0.50, $table->for( 'openai', 'gpt-5' )?->inputPerMillion );
	}
}
