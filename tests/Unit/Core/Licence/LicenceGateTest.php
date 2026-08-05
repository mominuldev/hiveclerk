<?php
/**
 * Licence gate tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Licence;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Licence\Feature;
use Hiveclerk\Core\Licence\LicenceChunkQuota;
use Hiveclerk\Core\Licence\LicenceClient;
use Hiveclerk\Core\Licence\LicenceGate;
use Hiveclerk\Core\Licence\LicenceService;
use Hiveclerk\Core\Licence\LicenceStatus;
use Hiveclerk\Core\Licence\Tier;
use Hiveclerk\Core\Support\Encryptor;
use Hiveclerk\Tests\Support\Chat\FrozenClock;
use Hiveclerk\Tests\Support\Chat\InMemoryAudit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * What a tier refuses, and what it never takes away.
 *
 * The second half is the important half. Every limit here is checked on
 * the way up — "may one more be made" — and none of them removes anything
 * that already exists, because a lapsed card is not a reason to archive
 * somebody's live support channel.
 *
 * @internal
 */
#[CoversClass( LicenceGate::class )]
#[CoversClass( LicenceChunkQuota::class )]
final class LicenceGateTest extends TestCase {

	/**
	 * Stored licence state, as the option would hold it.
	 *
	 * @var array<string, mixed>
	 */
	private array $state = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->state = array();

		Functions\stubs(
			array(
				'update_option'       => true,
				'delete_option'       => true,
				'do_action'           => null,
				'number_format_i18n'  => static fn ( $n ): string => (string) $n,
				'__'                  => static fn ( string $text ): string => $text,
				'_n'                  => static fn ( string $single, string $plural, int $n ): string => 1 === $n ? $single : $plural,
				'sanitize_text_field' => static fn ( string $value ): string => $value,
				'home_url'            => 'https://example.test',
				'untrailingslashit'   => static fn ( string $value ): string => rtrim( $value, '/' ),
			)
		);

		Functions\when( 'get_option' )->alias( fn ( string $name, $fallback = false ) => $this->option( $name, $fallback ) );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return $value;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function testAFreeSiteIsRefusedCrmAndToldWhichPlanCoversIt(): void {
		$gate    = $this->gate();
		$refusal = $gate->refusal( Feature::Crm );

		self::assertFalse( $gate->allows( Feature::Crm ) );
		self::assertInstanceOf( WP_Error::class, $refusal );
		self::assertSame( 'hvc_licence_required', $refusal->get_error_code() );
		self::assertStringContainsString( 'Pro', $refusal->get_error_message() );
	}

	public function testAProSiteIsAllowedCrmAndRefusedWhiteLabel(): void {
		$this->activate( Tier::Pro );

		$gate = $this->gate();

		self::assertTrue( $gate->allows( Feature::Crm ) );
		self::assertNull( $gate->refusal( Feature::EmailSequences ) );
		self::assertNotNull( $gate->refusal( Feature::WhiteLabel ) );
	}

	public function testAnExpiredProSiteLosesTheFeatureAndKeepsItsData(): void {
		$this->activate( Tier::Pro, LicenceStatus::Expired );

		$gate = $this->gate();

		self::assertFalse( $gate->allows( Feature::Crm ) );
		// The cap applies to what may still be indexed, never to what is
		// already there: 9,000 chunks stay searchable at the free tier's
		// 200-chunk allowance, and no more may be added.
		self::assertSame( 0, $gate->chunkHeadroom( 9000 ) );
		self::assertNotNull( $gate->chunkRefusal( 9000 ) );
	}

	public function testTheFreeClerkLimitRefusesTheSecondHireNotTheFirst(): void {
		$gate = $this->gate();

		self::assertSame( 1, $gate->clerkHeadroom( 0 ) );
		self::assertNull( $gate->clerkRefusal( 0 ) );

		self::assertSame( 0, $gate->clerkHeadroom( 1 ) );
		self::assertNotNull( $gate->clerkRefusal( 1 ) );
	}

	public function testAnUnlimitedTierReportsNoHeadroomLimitRatherThanZero(): void {
		$this->activate( Tier::Agency );

		$gate = $this->gate();

		self::assertNull( $gate->chunkHeadroom( 500000 ) );
		self::assertNull( $gate->clerkHeadroom( 40 ) );
		self::assertNull( $gate->chunkRefusal( 500000 ) );
	}

	public function testTheQuotaAdapterAnswersWhatIngestionAsks(): void {
		$quota = new LicenceChunkQuota( $this->gate() );

		self::assertSame( 200, $quota->remaining( 0 ) );
		self::assertSame( 50, $quota->remaining( 150 ) );
		// Never negative: a site that somehow exceeded its cap gets zero
		// headroom, not a number ingestion would read as "index more".
		self::assertSame( 0, $quota->remaining( 900 ) );
	}

	public function testTheGateIsFilterableForInstallsOutsideTheStandardArrangement(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'hiveclerk/licence/allows' === $hook ? true : $value;
			}
		);

		self::assertTrue( $this->gate()->allows( Feature::WhiteLabel ) );
	}

	/**
	 * Put a tier in the stored state.
	 *
	 * @param Tier          $tier   Tier.
	 * @param LicenceStatus $status Status.
	 * @return void
	 */
	private function activate( Tier $tier, LicenceStatus $status = LicenceStatus::Active ): void {
		$this->state = array(
			'tier'       => $tier->value,
			'status'     => $status->value,
			'masked'     => 'HVC-…9f2a',
			'sites'      => 1,
			'expires_at' => null,
			'checked_at' => '2026-08-05T00:00:00+00:00',
			'customer'   => null,
		);
	}

	/**
	 * Stand in for get_option().
	 *
	 * @param string $name     Option name.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	private function option( string $name, mixed $fallback ): mixed {
		return 'hiveclerk_licence_state' === $name ? $this->state : $fallback;
	}

	/**
	 * A gate over the stored state.
	 *
	 * @return LicenceGate
	 */
	private function gate(): LicenceGate {
		$clock = new FrozenClock( new DateTimeImmutable( '2026-08-05', new DateTimeZone( 'UTC' ) ) );

		return new LicenceGate(
			new LicenceService(
				new LicenceClient(),
				new Encryptor(),
				new AuditLogger( new InMemoryAudit(), $clock ),
				$clock
			)
		);
	}
}
