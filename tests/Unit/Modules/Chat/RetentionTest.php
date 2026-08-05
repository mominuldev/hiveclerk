<?php
/**
 * Retention policy tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Chat;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Core\Privacy\PrivacySettings;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Chat\Services\RetentionService;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use Hiveclerk\Tests\Support\Chat\InMemoryConversations;
use Hiveclerk\Tests\Support\Chat\InMemorySessions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Deleting history on a timer, which is the one operation in the product
 * that destroys customer data unattended.
 *
 * @internal
 */
#[CoversClass( RetentionService::class )]
final class RetentionTest extends TestCase {

	private InMemoryConversations $conversations;

	private RetentionService $retention;

	/**
	 * Settings the stubbed option store returns.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->stored = array();

		Functions\when( 'get_option' )->alias( fn (): array => $this->stored );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );

		$this->conversations = new InMemoryConversations();

		$this->retention = new RetentionService(
			$this->conversations,
			new InMemorySessions(),
			new PrivacySettings(
				new SettingsRepository(),
				new FrozenClock( new DateTimeImmutable( '2026-08-05 10:00:00', new DateTimeZone( 'UTC' ) ) )
			),
			new FrozenClock( new DateTimeImmutable( '2026-08-05 10:00:00', new DateTimeZone( 'UTC' ) ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testTheDefaultPolicyIsTwelveMonths(): void {
		$this->assertSame( 12, $this->retention->months() );
		$this->assertSame( '2025-08-05', $this->retention->cutoff()?->format( 'Y-m-d' ) );
	}

	public function testZeroMonthsMeansKeepEverything(): void {
		$this->given( '2019-01-01 00:00:00' );

		$retention = $this->withPolicy( 0 );

		$this->assertNull( $retention->cutoff() );
		$this->assertSame( 0, $retention->pending() );
		$this->assertSame( 0, $retention->purgeBatch() );
		$this->assertCount( 1, $this->conversations->saved );
	}

	public function testOnlyConversationsPastTheCutoffAreDeleted(): void {
		$old   = $this->given( '2026-01-01 09:00:00' );
		$fresh = $this->given( '2026-08-01 09:00:00' );

		$retention = $this->withPolicy( 3 );

		$this->assertSame( 1, $retention->pending() );
		$this->assertSame( 1, $retention->purgeBatch() );

		$this->assertArrayNotHasKey( (int) $old->id, $this->conversations->saved );
		$this->assertArrayHasKey( (int) $fresh->id, $this->conversations->saved );
	}

	public function testShorteningThePolicyAppliesToHistoryThatAlreadyExists(): void {
		$this->given( '2026-05-01 09:00:00' );

		$this->assertSame( 0, $this->withPolicy( 12 )->pending() );

		// The reason the cutoff is computed per run rather than stamped on
		// each row: an operator shortening the policy usually does so
		// because somebody asked them to delete what is already there. The
		// service is rebuilt because settings are cached for a request, and
		// a policy change is a new request.
		$this->assertSame( 1, $this->withPolicy( 1 )->pending() );
	}

	/**
	 * A retention service reading a given policy.
	 *
	 * @param int $months Months of history kept.
	 * @return RetentionService
	 */
	private function withPolicy( int $months ): RetentionService {
		$this->stored = array( 'privacy' => array( 'retention_months' => $months ) );

		return new RetentionService(
			$this->conversations,
			new InMemorySessions(),
			new PrivacySettings(
				new SettingsRepository(),
				new FrozenClock( new DateTimeImmutable( '2026-08-05 10:00:00', new DateTimeZone( 'UTC' ) ) )
			),
			new FrozenClock( new DateTimeImmutable( '2026-08-05 10:00:00', new DateTimeZone( 'UTC' ) ) )
		);
	}

	public function testAPolicyLongerThanTheCeilingIsClamped(): void {
		$this->assertSame( RetentionService::MAX_MONTHS, $this->withPolicy( 9999 )->months() );
	}

	public function testTheBatchIsBounded(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->given( '2020-01-01 09:00:00' );
		}

		$this->assertSame( 2, $this->withPolicy( 1 )->purgeBatch( 2 ) );
		$this->assertCount( 3, $this->conversations->saved );
	}

	/**
	 * Store a conversation that started at a given time.
	 *
	 * @param string $startedAt UTC timestamp.
	 * @return Conversation
	 */
	private function given( string $startedAt ): Conversation {
		return $this->conversations->save(
			new Conversation(
				id: null,
				uuid: Uuid::generate(),
				agentId: 1,
				startedAt: new DateTimeImmutable( $startedAt, new DateTimeZone( 'UTC' ) ),
			)
		);
	}
}
