<?php
/**
 * Clerk lifecycle tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Agents;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Ai\PricingTable;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentStatus;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Agents\Services\AgentService;
use Hiveclerk\Modules\Agents\Services\BudgetGuard;
use Hiveclerk\Modules\Agents\Services\PresetLibrary;
use Hiveclerk\Modules\Agents\Services\PublishPolicy;
use Hiveclerk\Modules\Agents\Support\AgentException;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use Hiveclerk\Tests\Support\Chat\InMemoryAgents;
use Hiveclerk\Tests\Support\Chat\InMemoryAudit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Hiring, publishing, pausing and copying a clerk.
 *
 * The cases worth writing down are the ones where the obvious
 * implementation is wrong: a new clerk that goes live because someone
 * posted `status: published`, a copy that inherits a spent budget and
 * stops answering on its first day, and a free-tier cap that fires on the
 * clerk that is already on duty.
 *
 * @internal
 */
#[CoversClass( AgentService::class )]
#[CoversClass( PublishPolicy::class )]
#[CoversClass( BudgetGuard::class )]
#[CoversClass( PresetLibrary::class )]
final class AgentLifecycleTest extends TestCase {

	private InMemoryAgents $agents;

	private InMemoryAudit $audit;

	private AgentService $service;

	private string $tier = 'pro';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'apply_filters' )->alias(
			fn ( string $hook, mixed $value ): mixed => 'hiveclerk/licence/tier' === $hook ? $this->tier : $value
		);
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias(
			static fn ( string $single, string $plural, int $number ): string => 1 === $number ? $single : $plural
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'sanitize_title' )->alias(
			static fn ( string $value ): string => trim(
				preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ) ?? '',
				'-'
			)
		);

		$clock    = new FrozenClock( new DateTimeImmutable( '2026-08-05 10:00:00', new DateTimeZone( 'UTC' ) ) );
		$settings = new SettingsRepository();

		$this->agents = new InMemoryAgents();
		$this->audit  = new InMemoryAudit();

		$this->service = new AgentService(
			$this->agents,
			new PresetLibrary(),
			new PublishPolicy( $this->agents, $settings ),
			new BudgetGuard( $this->agents, new PricingTable(), $clock ),
			new AuditLogger( $this->audit, $clock )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testHiringFillsTheRoleFromItsPreset(): void {
		$agent = $this->service->create( array( 'role_preset' => 'support' ) );

		$this->assertSame( 'support', $agent->rolePreset );
		$this->assertNotSame( '', (string) $agent->instructions );
		$this->assertStringContainsString( 'reference material', (string) $agent->instructions );
		$this->assertSame( 0.62, $agent->confidenceThreshold() );
	}

	public function testANewClerkIsAlwaysADraft(): void {
		// Publishing is a separate, audited act. A clerk that went live as
		// a side effect of being created is one nobody reviewed.
		$agent = $this->service->create(
			array(
				'role_preset' => 'sales',
				'status'      => 'published',
			)
		);

		$this->assertSame( AgentStatus::Draft, $agent->status );
	}

	public function testAnUnknownRoleIsRefusedRatherThanDefaulted(): void {
		$this->expectException( AgentException::class );

		$this->service->create( array( 'role_preset' => 'chief-vibes-officer' ) );
	}

	public function testSlugsDoNotCollide(): void {
		$first  = $this->service->create( array( 'name' => 'Ada' ) );
		$second = $this->service->create( array( 'name' => 'Ada' ) );

		$this->assertSame( 'ada', $first->slug );
		$this->assertSame( 'ada-2', $second->slug );
	}

	public function testPublishingRefusesAClerkThatCannotAnswer(): void {
		$agent = $this->service->create( array( 'role_preset' => 'support' ) );

		try {
			$this->service->publish( $agent );
			$this->fail( 'A clerk with no model should not go on duty.' );
		} catch ( AgentException $e ) {
			$this->assertSame( ErrorCode::VALIDATION_FAILED, $e->errorCode );
			$this->assertStringContainsString( 'model', $e->getMessage() );
		}

		$this->assertSame( AgentStatus::Draft, $agent->status );
	}

	public function testPublishingWorksOnceTheClerkIsConfigured(): void {
		$agent = $this->configured();

		$this->assertSame( AgentStatus::Published, $this->service->publish( $agent )->status );
		$this->assertContains( 'agent.published', $this->audit->recorded() );
	}

	public function testAFreeLicenceKeepsOneClerkOnDuty(): void {
		$this->tier = 'free';

		$first = $this->service->publish( $this->configured( 'Ada' ) );

		$this->assertSame( AgentStatus::Published, $first->status );

		try {
			$this->service->publish( $this->configured( 'Mira' ) );
			$this->fail( 'A second clerk should need a licence.' );
		} catch ( AgentException $e ) {
			$this->assertSame( ErrorCode::LICENCE_REQUIRED, $e->errorCode );
			$this->assertSame( 402, $e->status );
		}
	}

	public function testTheCapDoesNotFireOnTheClerkAlreadyOnDuty(): void {
		$this->tier = 'free';

		$agent = $this->service->publish( $this->configured( 'Ada' ) );

		// Saving an on-duty clerk re-publishes it, and refusing that would
		// make the cap fire on the one action that changes no count.
		$this->assertSame( AgentStatus::Published, $this->service->publish( $agent )->status );
	}

	public function testACopyStartsAsADraftWithAFreshBudget(): void {
		$agent                  = $this->configured();
		$agent->tokenBudget     = 500000;
		$agent->tokensUsedMonth = 480000;

		$this->agents->sources = array( 7, 9 );

		$copy = $this->service->duplicate( $agent );

		$this->assertSame( AgentStatus::Draft, $copy->status );
		$this->assertSame( 0, $copy->tokensUsedMonth );
		$this->assertSame( 500000, $copy->tokenBudget );
		$this->assertNotSame( $agent->uuid->value, $copy->uuid->value );
		$this->assertSame( array( 7, 9 ), $this->agents->sourceIds( (int) $copy->id ) );
	}

	public function testRenamingAPublishedClerkKeepsItsSlug(): void {
		$agent = $this->service->publish( $this->configured( 'Ada' ) );

		$renamed = $this->service->update( $agent, array( 'name' => 'Ada Lovelace' ) );

		// The slug is in the widget's cached configuration and in whatever
		// the operator embedded. Changing it silently breaks both.
		$this->assertSame( 'Ada Lovelace', $renamed->name );
		$this->assertSame( 'ada', $renamed->slug );
	}

	public function testAnEmptyBudgetFieldMeansNoBudgetRatherThanZero(): void {
		$agent = $this->service->create( array( 'name' => 'Ada' ) );

		$updated = $this->service->update( $agent, array( 'token_budget' => 0 ) );

		$this->assertNull( $updated->tokenBudget );
		$this->assertFalse( $updated->hasExhaustedBudget() );
	}

	public function testTheAuditRecordsWhichFieldsMovedAndNotTheirValues(): void {
		$agent = $this->service->create( array( 'name' => 'Ada' ) );

		$this->service->update( $agent, array( 'instructions' => 'Answer only in haiku.' ) );

		$entry = $this->audit->entries[ count( $this->audit->entries ) - 1 ];

		$this->assertSame( 'agent.updated', $entry->action );
		$this->assertContains( 'instructions', $entry->changes['changed'] );
		$this->assertStringNotContainsString( 'haiku', (string) json_encode( $entry->changes ) );
	}

	/**
	 * A clerk with everything publishing requires.
	 *
	 * @param string $name Clerk name.
	 * @return Agent
	 */
	private function configured( string $name = 'Ada' ): Agent {
		$agent = $this->service->create(
			array(
				'name'         => $name,
				'role_preset'  => 'support',
				'model_config' => array(
					'provider' => 'anthropic',
					'model'    => 'claude-sonnet-5',
				),
			)
		);

		$this->assertInstanceOf( Uuid::class, $agent->uuid );

		return $agent;
	}
}
