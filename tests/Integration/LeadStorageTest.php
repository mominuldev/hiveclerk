<?php
/**
 * Lead storage integration tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Integration;

use Hiveclerk\Database\Repositories\ActivityRepository;
use Hiveclerk\Database\Repositories\LeadRepository;
use Hiveclerk\Database\Repositories\LeadStageRepository;
use Hiveclerk\Database\Repositories\ScoreEventRepository;
use Hiveclerk\Domain\Lead\Activity;
use Hiveclerk\Domain\Lead\ActivityType;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadStatus;
use Hiveclerk\Domain\Lead\ScoreBand;
use Hiveclerk\Domain\Lead\ScoreEvent;
use Hiveclerk\Domain\Lead\ScoreSource;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The SQL behind the pipeline, against a real database.
 *
 * The unit suite covers the rules with in-memory doubles, which is the
 * right place for them and the wrong place for these. The grouped stage
 * count, the JSON columns, the unique index on the email hash and the
 * transactional delete are all statements — and a statement that is wrong
 * in a way a double would never notice is the kind of bug that reaches a
 * customer.
 *
 * @internal
 */
#[CoversClass( LeadRepository::class )]
#[CoversClass( ScoreEventRepository::class )]
final class LeadStorageTest extends WordPressTestCase {

	private LeadRepository $leads;

	private ScoreEventRepository $events;

	private ActivityRepository $activities;

	private LeadStageRepository $stages;

	/**
	 * Leads this test created.
	 *
	 * @var array<int, int>
	 */
	private array $created = array();

	protected function setUp(): void {
		parent::setUp();

		$this->leads      = new LeadRepository();
		$this->events     = new ScoreEventRepository();
		$this->activities = new ActivityRepository();
		$this->stages     = new LeadStageRepository();
	}

	/**
	 * Remove anything left behind.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->created as $id ) {
			$this->leads->delete( $id );
		}

		$this->created = array();

		parent::tearDown();
	}

	/**
	 * A stored lead.
	 *
	 * @param string|null $email Address, or null for an anonymous lead.
	 * @return Lead
	 */
	private function given( ?string $email = null ): Lead {
		$address = $email ?? sprintf( 'test-%s@hiveclerk.test', bin2hex( random_bytes( 6 ) ) );

		$lead = $this->leads->save(
			new Lead(
				id: null,
				uuid: Uuid::generate(),
				email: $address,
				emailHash: Lead::hashEmail( $address ),
				company: 'Nordwind Outdoor',
				source: 'rafi',
			)
		);

		$this->created[] = (int) $lead->id;

		return $lead;
	}

	public function testALeadSurvivesARoundTrip(): void {
		$lead = $this->given();

		$lead->customFields = array( 'budget' => '€12,000' );
		$lead->consent      = array( 'marketing' => true );
		$lead->status       = LeadStatus::Qualified;

		$this->leads->save( $lead );

		$reloaded = $this->leads->find( (int) $lead->id );

		$this->assertNotNull( $reloaded );
		$this->assertSame( '€12,000', $reloaded->answer( 'budget' ) );
		$this->assertTrue( $reloaded->consent['marketing'] );
		$this->assertSame( LeadStatus::Qualified, $reloaded->status );
		$this->assertSame( 'Nordwind Outdoor', $reloaded->company );
	}

	public function testTheEmailHashFindsTheSamePersonAgain(): void {
		$lead = $this->given( 'dedup-' . bin2hex( random_bytes( 4 ) ) . '@nordwind.test' );

		$found = $this->leads->findByEmailHash( (string) $lead->emailHash );

		$this->assertNotNull( $found );
		$this->assertSame( $lead->id, $found->id );
	}

	public function testTheScoreLogIsAppendOnlyAndSumsToTheColumn(): void {
		$lead = $this->given();

		$total = 0;

		foreach ( array( 15, 20, -5 ) as $points ) {
			$total += $points;

			$this->events->append(
				new ScoreEvent(
					id: null,
					leadId: (int) $lead->id,
					ruleId: 'rule_' . $points,
					ruleLabel: 'A rule worth ' . $points,
					source: ScoreSource::Rule,
					points: $points,
					scoreAfter: $total,
				)
			);
		}

		$this->leads->updateScore( (int) $lead->id, $total, ScoreBand::Warm );

		$reloaded = $this->leads->find( (int) $lead->id );

		$this->assertNotNull( $reloaded );
		$this->assertSame( $total, $reloaded->score );
		$this->assertSame( ScoreBand::Warm, $reloaded->band );
		$this->assertSame( $total, $this->events->total( (int) $lead->id ) );
		$this->assertCount( 3, $this->events->forLead( (int) $lead->id ) );
	}

	public function testAwardedRuleIdsComeBackForTheOnceCheck(): void {
		$lead = $this->given();

		$this->events->append(
			new ScoreEvent(
				id: null,
				leadId: (int) $lead->id,
				ruleId: 'business_email',
				source: ScoreSource::Rule,
				points: 15,
				scoreAfter: 15,
			)
		);

		// An AI event has no rule id, and must not appear in the list the
		// once-check reads — otherwise a null would suppress a rule.
		$this->events->append(
			new ScoreEvent(
				id: null,
				leadId: (int) $lead->id,
				source: ScoreSource::Ai,
				points: 12,
				scoreAfter: 27,
				rationale: 'Named a decision date.',
			)
		);

		$this->assertSame( array( 'business_email' ), $this->events->awardedRuleIds( (int) $lead->id ) );
	}

	public function testStageCountsComeBackInOneQuery(): void {
		$stages = $this->stages->all();

		$this->assertNotEmpty( $stages, 'The M0010 migration seeds the default stages.' );

		$stage = $stages[0];

		$first  = $this->given();
		$second = $this->given();

		$first->stageId  = $stage->id;
		$second->stageId = $stage->id;

		$this->leads->save( $first );
		$this->leads->save( $second );

		$counts = $this->leads->countsByStage();

		$this->assertArrayHasKey( (int) $stage->id, $counts );
		$this->assertGreaterThanOrEqual( 2, $counts[ (int) $stage->id ] );
	}

	public function testDeletingALeadTakesItsEventsAndTimelineWithIt(): void {
		$lead = $this->given();
		$id   = (int) $lead->id;

		$this->events->append(
			new ScoreEvent(
				id: null,
				leadId: $id,
				ruleId: 'phone_given',
				source: ScoreSource::Rule,
				points: 10,
				scoreAfter: 10,
			)
		);

		$this->activities->record(
			new Activity(
				id: null,
				type: ActivityType::LeadCaptured,
				title: 'Lead captured',
				leadId: $id,
			)
		);

		$this->assertTrue( $this->leads->delete( $id ) );

		// With no database-level foreign keys, an interrupted delete would
		// leave score events attributing points to a lead that no longer
		// exists — and the scoring screen would show them.
		$this->assertNull( $this->leads->find( $id ) );
		$this->assertSame( array(), $this->events->forLead( $id ) );
		$this->assertSame( array(), $this->activities->timeline( $id ) );

		$this->created = array_values( array_filter( $this->created, static fn ( int $kept ): bool => $kept !== $id ) );
	}

	public function testTheSearchFilterMatchesEmailAndCompany(): void {
		$marker = 'search' . bin2hex( random_bytes( 4 ) );

		$lead = $this->given( $marker . '@nordwind.test' );

		$found = $this->leads->paginate( new Pagination( 1, 25 ), array( 'search' => $marker ) );

		$this->assertCount( 1, $found );
		$this->assertSame( $lead->id, $found[0]->id );
	}

	public function testAStageIsEmptiedRatherThanTakingItsLeadsWithIt(): void {
		$stages = $this->stages->all();
		$from   = $stages[0];
		$to     = $stages[1];

		$lead          = $this->given();
		$lead->stageId = $from->id;

		$this->leads->save( $lead );

		$moved = $this->leads->reassignStage( (int) $from->id, (int) $to->id );

		$this->assertGreaterThanOrEqual( 1, $moved );

		$reloaded = $this->leads->find( (int) $lead->id );

		$this->assertNotNull( $reloaded );
		$this->assertSame( $to->id, $reloaded->stageId );
	}
}
