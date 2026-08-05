<?php
/**
 * GDPR export tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Privacy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Core\Privacy\PersonalDataExporter;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\Visitor;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Tests\Support\Chat\InMemoryConversations;
use Hiveclerk\Tests\Support\Chat\InMemoryMessages;
use Hiveclerk\Tests\Support\Email\InMemoryEmailLog;
use Hiveclerk\Tests\Support\Leads\InMemoryActivities;
use Hiveclerk\Tests\Support\Leads\InMemoryLeads;
use Hiveclerk\Tests\Support\Leads\InMemoryVisitors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a subject access request returns, and what it withholds.
 *
 * @internal
 */
#[CoversClass( PersonalDataExporter::class )]
final class PersonalDataExporterTest extends TestCase {

	private InMemoryLeads $leads;

	private InMemoryConversations $conversations;

	private InMemoryVisitors $visitors;

	private PersonalDataExporter $exporter;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'add_filter' )->justReturn( true );

		$this->leads         = new InMemoryLeads();
		$this->conversations = new InMemoryConversations();
		$this->visitors      = new InMemoryVisitors();

		$this->exporter = new PersonalDataExporter(
			$this->leads,
			$this->conversations,
			new InMemoryMessages(),
			$this->visitors,
			new InMemoryActivities(),
			new InMemoryEmailLog()
		);
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * An address the site holds nothing for finishes immediately.
	 *
	 * WordPress calls an exporter until it reports `done`. A `false` here
	 * on an address with no data is an export screen that never finishes.
	 *
	 * @return void
	 */
	public function testAnUnknownAddressIsDoneOnTheFirstPage(): void {
		$result = $this->exporter->export( 'nobody@example.com', 1 );

		$this->assertSame( array(), $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * So does something that is not an address.
	 *
	 * @return void
	 */
	public function testAMalformedAddressIsDone(): void {
		$this->assertTrue( $this->exporter->export( 'not-an-address', 1 )['done'] );
	}

	/**
	 * Transcripts page, and the last page says so.
	 *
	 * @return void
	 */
	public function testTranscriptsArePaginatedAndReportDoneOnTheLastPage(): void {
		$this->seedLead();

		// Eleven conversations against a page size of ten: page one must
		// report more to come, page two must close the export.
		for ( $i = 1; $i <= 11; $i++ ) {
			$this->conversations->saved[ $i ] = new Conversation(
				id: $i,
				uuid: Uuid::generate(),
				agentId: 1,
				leadId: 1
			);
		}

		$first = $this->exporter->export( 'tomas@example.com', 1 );
		$this->assertFalse( $first['done'] );

		$second = $this->exporter->export( 'tomas@example.com', 2 );
		$this->assertTrue( $second['done'] );

		$transcripts = static fn ( array $result ): int => count(
			array_filter(
				$result['data'],
				static fn ( array $item ): bool => 'hiveclerk-conversations' === $item['group_id']
			)
		);

		$this->assertSame( 10, $transcripts( $first ) );
		$this->assertSame( 1, $transcripts( $second ) );
	}

	/**
	 * The profile is not repeated on every page.
	 *
	 * @return void
	 */
	public function testTheProfileIsEmittedOnceRatherThanOnEveryPage(): void {
		$this->seedLead();

		for ( $i = 1; $i <= 11; $i++ ) {
			$this->conversations->saved[ $i ] = new Conversation(
				id: $i,
				uuid: Uuid::generate(),
				agentId: 1,
				leadId: 1
			);
		}

		$groups = static fn ( array $result ): array => array_column( $result['data'], 'group_id' );

		$this->assertContains( 'hiveclerk-lead', $groups( $this->exporter->export( 'tomas@example.com', 1 ) ) );
		$this->assertNotContains( 'hiveclerk-lead', $groups( $this->exporter->export( 'tomas@example.com', 2 ) ) );
	}

	/**
	 * The hashed IP is acknowledged, never handed over.
	 *
	 * A SHA-256 digest in an emailed ZIP tells the person nothing and
	 * hands anyone who intercepts it a stable identifier.
	 *
	 * @return void
	 */
	public function testTheIpHashIsDescribedRatherThanDisclosed(): void {
		$this->seedLead();

		$ipHash = str_repeat( 'f', 64 );

		$this->visitors->saved[] = new Visitor(
			id: 3,
			uuid: Uuid::generate(),
			leadId: 1,
			ipHash: $ipHash
		);

		$exported = (string) json_encode( $this->exporter->export( 'tomas@example.com', 1 ) );

		$this->assertStringNotContainsString( $ipHash, $exported );
		$this->assertStringContainsString( 'one-way hash', $exported );
	}

	/**
	 * Columns the site holds nothing in are left out.
	 *
	 * @return void
	 */
	public function testEmptyFieldsAreOmitted(): void {
		$this->seedLead();

		$result = $this->exporter->export( 'tomas@example.com', 1 );
		$names  = array_column( $result['data'][0]['data'], 'name' );

		$this->assertContains( 'Email', $names );
		$this->assertNotContains( 'Phone', $names );
	}

	/**
	 * Store a lead owning the address under test.
	 *
	 * @return void
	 */
	private function seedLead(): void {
		$this->leads->saved[1] = new Lead(
			id: 1,
			uuid: Uuid::generate(),
			email: 'tomas@example.com',
			emailHash: Lead::hashEmail( 'tomas@example.com' )
		);
	}
}
