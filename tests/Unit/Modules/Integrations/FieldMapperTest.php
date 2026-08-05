<?php
/**
 * Field mapper tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\Integrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Domain\Integration\FieldMap;
use Hiveclerk\Domain\Integration\Integration;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadStage;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Integrations\Services\FieldMapper;
use Hiveclerk\Tests\Support\Chat\InMemoryConversations;
use Hiveclerk\Tests\Support\Chat\InMemoryMessages;
use Hiveclerk\Tests\Support\Leads\InMemoryStages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What actually reaches a CRM.
 *
 * The rule worth protecting is that a blank field is omitted rather than
 * sent empty. Every connector here upserts, so every push is also an
 * update — and `company => ""` overwrites a company name a salesperson
 * typed in by hand this morning.
 *
 * @internal
 */
#[CoversClass( FieldMapper::class )]
final class FieldMapperTest extends TestCase {

	private FieldMapper $mapper;

	private InMemoryStages $stages;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( '__' )->returnArg( 1 );

		$this->stages = new InMemoryStages();

		$this->mapper = new FieldMapper(
			$this->stages,
			new InMemoryConversations(),
			new InMemoryMessages()
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_it_maps_what_the_lead_has(): void {
		$payload = $this->mapper->build(
			$this->lead( array( 'firstName' => 'Sam' ) ),
			$this->integration( array( 'first_name' => 'firstname' ) )
		);

		$this->assertSame( array( 'firstname' => 'Sam' ), $payload );
	}

	public function test_it_omits_a_field_the_lead_does_not_have(): void {
		// Not `company => ''`. An empty string is an instruction to clear
		// whatever the CRM already holds.
		$payload = $this->mapper->build(
			$this->lead(),
			$this->integration(
				array(
					'email'   => 'email',
					'company' => 'company',
				)
			)
		);

		$this->assertArrayNotHasKey( 'company', $payload );
		$this->assertSame( 'sam@example.com', $payload['email'] );
	}

	public function test_the_score_is_sent_as_text(): void {
		$payload = $this->mapper->build(
			$this->lead( array( 'score' => 72 ) ),
			$this->integration( array( 'score' => 'hvc_score' ) )
		);

		$this->assertSame( '72', $payload['hvc_score'] );
	}

	public function test_a_zero_score_is_still_sent(): void {
		// Zero is a real score and a meaningful one. Dropping it as
		// "empty" would leave a CRM field showing last week's number.
		$payload = $this->mapper->build(
			$this->lead( array( 'score' => 0 ) ),
			$this->integration( array( 'score' => 'hvc_score' ) )
		);

		$this->assertSame( '0', $payload['hvc_score'] );
	}

	public function test_it_maps_a_qualification_answer(): void {
		$payload = $this->mapper->build(
			$this->lead( array( 'customFields' => array( 'budget' => 'Around £20k' ) ) ),
			$this->integration( array( 'answer:budget' => 'hvc_budget' ) )
		);

		$this->assertSame( 'Around £20k', $payload['hvc_budget'] );
	}

	public function test_it_resolves_the_stage_by_name(): void {
		$this->stages->save( new LeadStage( id: 3, name: 'Demo booked', slug: 'demo-booked' ) );

		$payload = $this->mapper->build(
			$this->lead( array( 'stageId' => 3 ) ),
			$this->integration( array( 'stage' => 'lifecyclestage' ) )
		);

		$this->assertSame( 'Demo booked', $payload['lifecyclestage'] );
	}

	public function test_the_transcript_is_withheld_unless_the_operator_asked(): void {
		// The most sensitive thing this plugin holds. Copying it into a
		// third-party SaaS is a decision, not a default.
		$integration = $this->integration( array( 'transcript' => 'notes' ) );

		$this->assertArrayNotHasKey(
			'notes',
			$this->mapper->build( $this->lead(), $integration )
		);
	}

	public function test_sources_include_the_configured_answer_keys(): void {
		$keys = array_column( $this->mapper->sources( array( 'budget' ) ), 'key' );

		$this->assertContains( 'answer:budget', $keys );
		$this->assertContains( 'email', $keys );
	}

	public function test_email_is_reported_locked_to_the_mapping_screen(): void {
		$email = null;

		foreach ( $this->mapper->sources() as $source ) {
			if ( 'email' === $source['key'] ) {
				$email = $source;
			}
		}

		$this->assertNotNull( $email );
		$this->assertTrue( $email['locked'] );
	}

	/**
	 * A lead with sensible defaults and whatever the test overrides.
	 *
	 * @param array<string, mixed> $overrides Property values.
	 * @return Lead
	 */
	private function lead( array $overrides = array() ): Lead {
		$lead = new Lead(
			id: 1,
			uuid: Uuid::generate(),
			email: 'sam@example.com',
		);

		foreach ( $overrides as $property => $value ) {
			$lead->{$property} = $value;
		}

		return $lead;
	}

	/**
	 * A connected integration with the given mapping.
	 *
	 * @param array<string, string> $mapping Field mapping.
	 * @return Integration
	 */
	private function integration( array $mapping ): Integration {
		return new Integration(
			id: 1,
			provider: 'hubspot',
			fieldMap: FieldMap::fromArray( $mapping ),
		);
	}
}
