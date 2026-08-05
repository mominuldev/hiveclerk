<?php
/**
 * Usage event tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Domain\Usage;

use Hiveclerk\Domain\Usage\UsageEvent;
use Hiveclerk\Domain\Usage\UsageKind;
use Hiveclerk\Domain\Usage\UsageSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass( UsageEvent::class )]
#[CoversClass( UsageKind::class )]
#[CoversClass( UsageSummary::class )]
final class UsageEventTest extends TestCase {

	public function testAnUnpricedCallRecordsNoCostRatherThanZero(): void {
		$event = new UsageEvent(
			kind: UsageKind::Chat,
			provider: 'openrouter',
			model: 'some/preview-model',
			tokensIn: 900,
			tokensOut: 120
		);

		// Zero would be a claim that the call was free, which sums into a
		// spend figure that is quietly wrong.
		$this->assertNull( $event->cost );
		$this->assertFalse( $event->isPriced() );
		$this->assertSame( 1020, $event->totalTokens() );
	}

	public function testAPricedCallReportsItsCost(): void {
		$event = new UsageEvent(
			kind: UsageKind::Chat,
			provider: 'anthropic',
			model: 'claude-sonnet-4-5',
			cost: 0.0123
		);

		$this->assertTrue( $event->isPriced() );
		$this->assertSame( 0.0123, $event->cost );
	}

	public function testIndexingIsNotChargedToAConversation(): void {
		// Cost per conversation would be wrong on every day a knowledge
		// base is re-indexed if embedding counted as conversational.
		$this->assertFalse( UsageKind::Embedding->isConversational() );
		$this->assertTrue( UsageKind::Chat->isConversational() );
		$this->assertTrue( UsageKind::Summary->isConversational() );
	}

	public function testAttributionReturnsANewEventWithTheSameFigures(): void {
		$event      = new UsageEvent( UsageKind::Chat, 'openai', 'gpt-5-mini', 10, 20, 0.5 );
		$attributed = $event->attributedTo( 7, 42 );

		$this->assertNull( $event->agentId );
		$this->assertSame( 7, $attributed->agentId );
		$this->assertSame( 42, $attributed->conversationId );
		$this->assertSame( 0.5, $attributed->cost );
	}

	public function testASummaryReportsWhetherEverythingCouldBePriced(): void {
		$complete = new UsageSummary( 'all', calls: 10, cost: 1.5 );
		$partial  = new UsageSummary( 'all', calls: 10, cost: 1.5, unpriced: 3 );

		$this->assertTrue( $complete->isComplete() );
		$this->assertFalse( $partial->isComplete() );

		// A total that omits calls it could not price must not look
		// identical to one that priced everything.
		$this->assertSame( 3, $partial->jsonSerialize()['unpriced'] );
		$this->assertFalse( $partial->jsonSerialize()['complete'] );
	}

	public function testUnknownStoredKindFallsBackRatherThanThrowing(): void {
		$this->assertSame( UsageKind::Chat, UsageKind::fromStorage( 'something-removed' ) );
		$this->assertSame( UsageKind::Embedding, UsageKind::fromStorage( 'embedding' ) );
	}
}
