<?php
/**
 * Scoring service tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Leads;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Domain\Lead\ActivityType;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\ScoreBand;
use Hiveclerk\Domain\Lead\ScoreEvent;
use Hiveclerk\Domain\Lead\ScoreSource;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Infrastructure\Http\OutboundUrlGuard;
use Hiveclerk\Modules\Leads\Services\LeadNotifier;
use Hiveclerk\Modules\Leads\Services\ScoringPolicy;
use Hiveclerk\Modules\Leads\Services\ScoringService;
use Hiveclerk\Modules\Leads\Services\SignalCollector;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use Hiveclerk\Tests\Support\Chat\InMemoryConversations;
use Hiveclerk\Tests\Support\Chat\InMemoryMessages;
use Hiveclerk\Tests\Support\Leads\InMemoryActivities;
use Hiveclerk\Tests\Support\Leads\InMemoryLeads;
use Hiveclerk\Tests\Support\Leads\InMemoryScoreEvents;
use Hiveclerk\Tests\Support\Leads\InMemoryVisitors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The append-only log and the number it materialises (D7 §5.2).
 *
 * The behaviour worth protecting here is that the breakdown a
 * salesperson reads adds up to the score above it. Every test below is
 * some version of that: the event carries its own running total, the
 * column follows the log rather than the other way round, and an
 * adjustment nobody can explain is refused rather than stored.
 *
 * @internal
 */
#[CoversClass( ScoringService::class )]
final class ScoringServiceTest extends TestCase {

	private InMemoryLeads $leads;

	private InMemoryScoreEvents $events;

	private InMemoryActivities $activities;

	private ScoringService $scoring;

	/**
	 * Emails wp_mail() was asked to send.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mail = array();

	/**
	 * Stored settings, keyed by option name.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->mail    = array();
		$this->options = array();

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'is_email' )->alias(
			static fn ( string $email ): bool => false !== filter_var( $email, FILTER_VALIDATE_EMAIL )
		);
		Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/admin.php' );
		Functions\when( 'add_query_arg' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( mixed $value ): string|false => json_encode( $value ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
		Functions\when( 'get_option' )->alias(
			fn ( string $name, mixed $fallback = false ): mixed => $this->options[ $name ] ?? $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, mixed $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( string $name, mixed $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, string $subject, string $body ): bool {
				$this->mail[] = array(
					'to'      => $to,
					'subject' => $subject,
					'body'    => $body,
				);

				return true;
			}
		);

		$this->leads      = new InMemoryLeads();
		$this->events     = new InMemoryScoreEvents();
		$this->activities = new InMemoryActivities();

		$policy = new ScoringPolicy( new SettingsRepository() );

		$this->scoring = new ScoringService(
			$this->leads,
			$this->events,
			$this->activities,
			$policy,
			new SignalCollector(
				new InMemoryMessages(),
				new InMemoryConversations(),
				new InMemoryVisitors()
			),
			new LeadNotifier( $policy, $this->activities, new OutboundUrlGuard() ),
			new FrozenClock( new DateTimeImmutable( '2026-08-05 12:00:00', new DateTimeZone( 'UTC' ) ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A lead with an address and a phone number, already stored.
	 *
	 * @return Lead
	 */
	private function lead(): Lead {
		return $this->leads->save(
			new Lead(
				id: null,
				uuid: Uuid::generate(),
				email: 'sarah@nordwind.de',
				emailHash: Lead::hashEmail( 'sarah@nordwind.de' ),
				phone: '+49 30 1234567',
				company: 'Nordwind Outdoor',
			)
		);
	}

	public function testEachRuleThatFiresBecomesOneEventAndOneActivity(): void {
		$lead = $this->lead();

		$awarded = $this->scoring->applyRules( $lead );

		self::assertGreaterThan( 0, $awarded );
		self::assertNotEmpty( $this->events->events );
		self::assertCount(
			count( $this->events->events ),
			$this->activities->ofType( ActivityType::ScoreChanged )
		);
	}

	public function testTheStoredTotalEqualsTheSumOfTheEvents(): void {
		$lead = $this->lead();

		$this->scoring->applyRules( $lead );

		self::assertSame( $this->events->total( (int) $lead->id ), $lead->score );
	}

	public function testEachEventCarriesTheTotalAsItStoodAfterIt(): void {
		$lead = $this->lead();

		$this->scoring->applyRules( $lead );

		$running = 0;

		foreach ( $this->events->forLead( (int) $lead->id ) as $event ) {
			$running += $event->points;

			// Stamped at write time rather than derived on read. A total
			// recalculated later would change retrospectively the first
			// time somebody edits a rule's weight.
			self::assertSame( $running, $event->scoreAfter );
		}
	}

	public function testASecondPassAwardsNothingNew(): void {
		$lead = $this->lead();

		$first = $this->scoring->applyRules( $lead );

		self::assertSame( 0, $this->scoring->applyRules( $lead ) );
		self::assertSame( $first, $lead->score );
	}

	public function testAnAiAdjustmentWithoutARationaleIsRefused(): void {
		$lead   = $this->lead();
		$before = count( $this->events->events );

		self::assertFalse( $this->scoring->applyAiAdjustment( $lead, 12, '   ', 'Buying intent' ) );
		self::assertCount( $before, $this->events->events );
	}

	public function testAnAiAdjustmentWithARationaleIsRecordedWithIt(): void {
		$lead = $this->lead();

		self::assertTrue(
			$this->scoring->applyAiAdjustment(
				$lead,
				12,
				'Asked about implementation timeline and named a decision date.',
				'Buying-intent language'
			)
		);

		$event = end( $this->events->events );

		self::assertInstanceOf( ScoreEvent::class, $event );
		self::assertSame( ScoreSource::Ai, $event->source );
		self::assertSame( 'Buying-intent language', $event->ruleLabel );
		self::assertNotNull( $event->rationale );
	}

	public function testANegativeAdjustmentLowersTheScore(): void {
		$lead = $this->lead();

		$this->scoring->applyRules( $lead );

		$before = $lead->score;

		$this->scoring->applyManualAdjustment( $lead, -15, 'Student enquiry', 7 );

		self::assertSame( $before - 15, $lead->score );
		self::assertSame( $this->events->total( (int) $lead->id ), $lead->score );
	}

	public function testTheBandFollowsTheScore(): void {
		$lead = $this->lead();

		self::assertSame( ScoreBand::Cold, $lead->band );

		$this->scoring->applyManualAdjustment( $lead, 80, 'Signed off by the buyer' );

		self::assertSame( ScoreBand::Qualified, $lead->band );
	}

	public function testCrossingTheThresholdNotifiesOnceAndOnlyOnce(): void {
		$this->options['hiveclerk_settings'] = array(
			'leads' => array(
				'alerts' => array(
					'enabled' => true,
					'score'   => 50,
					'emails'  => array( 'sales@example.test' ),
				),
			),
		);

		$lead = $this->lead();

		$this->scoring->applyManualAdjustment( $lead, 80, 'Ready to buy' );

		self::assertCount( 1, $this->mail );

		// A lead whose score keeps moving crosses the threshold on every
		// write from the point of view of the write that did it. Four
		// emails about one person is how a sales team learns to filter
		// this sender into a folder.
		$this->scoring->applyManualAdjustment( $lead, 5, 'And again' );

		self::assertCount( 1, $this->mail );
	}

	public function testRecalculateRepairsADriftedTotal(): void {
		$lead = $this->lead();

		$this->scoring->applyRules( $lead );

		// The two writes are separate, so a crash between them is possible.
		// This is what makes that recoverable rather than permanent.
		$lead->score = 3;

		self::assertSame( $this->events->total( (int) $lead->id ), $this->scoring->recalculate( $lead ) );
	}
}
