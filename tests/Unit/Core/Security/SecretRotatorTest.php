<?php
/**
 * Key rotation sweep tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Security;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Ai\KeyResolver;
use Hiveclerk\Core\Licence\LicenceService;
use Hiveclerk\Core\Security\SecretRotator;
use Hiveclerk\Core\Support\Encryptor;
use Hiveclerk\Tests\Support\Integration\InMemoryIntegrationRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What must be true before an operator is allowed to finish a rotation.
 *
 * The dangerous state is not "rotation failed" — it is "rotation reported
 * success while a secret nobody looked at was still encrypted under the key
 * that was just thrown away". Every test here is about that.
 *
 * @internal
 */
#[CoversClass( SecretRotator::class )]
final class SecretRotatorTest extends TestCase {

	/**
	 * Stand-in for the options table.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	private Encryptor $encryptor;
	private InMemoryIntegrationRepository $integrations;
	private SecretRotator $rotator;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array(
			'hiveclerk_encryption_salt' => 'ab54c1f0e7d29b3a6c8145f0937bde12ab54c1f0e7d29b3a6c8145f0937bde12',
		);

		Functions\when( 'get_option' )->alias(
			fn( string $name, $fallback = false ) => $this->options[ $name ] ?? $fallback
		);
		Functions\when( 'add_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] ??= $value;

				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ) {
				unset( $this->options[ $name ] );

				return true;
			}
		);

		$this->encryptor    = new Encryptor();
		$this->integrations = new InMemoryIntegrationRepository();
		$this->rotator      = new SecretRotator( $this->encryptor, $this->integrations );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Fill all three stores with secrets under the current key.
	 *
	 * @return array<string, string> Label => plaintext.
	 */
	private function seed(): array {
		$this->options[ LicenceService::KEY_OPTION ] = $this->encryptor->encrypt( 'HVC-LICENCE-0001' );
		$this->options[ KeyResolver::OPTION ]        = array(
			'openai' => array( 'key' => $this->encryptor->encrypt( 'sk-openai-0001' ) ),
			'gemini' => array( 'key' => $this->encryptor->encrypt( 'sk-gemini-0002' ) ),
		);

		$this->integrations->add( 1, 'hubspot', $this->encryptor->encrypt( 'tok-hubspot-0003' ) );

		return array(
			'licence' => 'HVC-LICENCE-0001',
			'openai'  => 'sk-openai-0001',
			'gemini'  => 'sk-gemini-0002',
			'hubspot' => 'tok-hubspot-0003',
		);
	}

	public function testNothingIsOutstandingBeforeARotation(): void {
		$this->seed();

		self::assertSame( array(), $this->rotator->outstanding() );
	}

	public function testEveryStoreIsFoundOnceTheWindowOpens(): void {
		$this->seed();

		$this->rotator->begin();

		/*
		 * Four secrets across three different kinds of storage — a plain
		 * option, an option holding a map of records, and a table column.
		 * A rotation that only walked the obvious one would pass every
		 * other test in this file.
		 */
		self::assertCount( 4, $this->rotator->outstanding() );
	}

	public function testASweepRewritesEverythingAndValuesSurvive(): void {
		$expected = $this->seed();

		$this->rotator->begin();

		$result = $this->rotator->sweep();

		self::assertSame( 4, $result['rewritten'] );
		self::assertSame( 0, $result['remaining'] );
		self::assertSame( 0, $result['unreadable'] );

		self::assertTrue( $this->rotator->finish() );

		// The real assertion: after the old key is gone, every secret still
		// reads back as what the customer typed in.
		self::assertSame(
			$expected['licence'],
			$this->encryptor->decrypt( (string) $this->options[ LicenceService::KEY_OPTION ] )
		);
		self::assertSame(
			$expected['openai'],
			$this->encryptor->decrypt( $this->options[ KeyResolver::OPTION ]['openai']['key'] )
		);
		self::assertSame(
			$expected['gemini'],
			$this->encryptor->decrypt( $this->options[ KeyResolver::OPTION ]['gemini']['key'] )
		);
		self::assertSame(
			$expected['hubspot'],
			$this->encryptor->decrypt( (string) $this->integrations->secret( 1 ) )
		);
	}

	public function testFinishIsRefusedWhileAnythingIsStillReadableOnlyByTheOldKey(): void {
		$this->seed();
		$this->rotator->begin();

		// One secret's worth of budget, four secrets outstanding.
		$this->rotator->sweep( 1 );

		/*
		 * The safety property. Closing here would destroy three secrets
		 * that are still perfectly readable, which is the failure mode an
		 * operator cannot undo and would not notice until a sync broke.
		 */
		self::assertFalse( $this->rotator->finish() );
		self::assertTrue( $this->encryptor->isRotating() );
	}

	public function testTheSweepIsBounded(): void {
		$this->seed();
		$this->rotator->begin();

		$result = $this->rotator->sweep( 2 );

		self::assertSame( 2, $result['rewritten'] );
		self::assertSame( 2, $result['remaining'] );
	}

	public function testASweepResumesRatherThanRestarting(): void {
		$this->seed();
		$this->rotator->begin();

		$this->rotator->sweep( 2 );
		$second = $this->rotator->sweep( 2 );

		// Already-rewritten secrets are current, so the second pass picks up
		// the two that are left rather than doing the first two again.
		self::assertSame( 2, $second['rewritten'] );
		self::assertSame( 0, $second['remaining'] );
	}

	public function testAnUnreadableSecretIsCountedAndLeftAlone(): void {
		$this->seed();

		$corrupt = 'v2:' . base64_encode( random_bytes( 64 ) );

		$this->options[ KeyResolver::OPTION ]['openai']['key'] = $corrupt;

		$this->rotator->begin();

		$result = $this->rotator->sweep();

		self::assertSame( 1, $result['unreadable'] );

		/*
		 * Not deleted. It is already lost, and the row is the only remaining
		 * evidence that a key was configured there at all — which is what
		 * tells the operator which one to paste back in.
		 */
		self::assertSame( $corrupt, $this->options[ KeyResolver::OPTION ]['openai']['key'] );
	}

	public function testAnUnreadableSecretDoesNotBlockFinishing(): void {
		$this->seed();

		$this->options[ KeyResolver::OPTION ]['openai']['key'] = 'v2:' . base64_encode( random_bytes( 64 ) );

		$this->rotator->begin();
		$this->rotator->sweep();

		// It cannot be rewritten by anyone, so waiting for it would strand
		// the operator in a rotation they can never close.
		self::assertTrue( $this->rotator->finish() );
	}

	public function testASecondRotationIsRefusedWhileOneIsOpen(): void {
		$this->seed();

		self::assertTrue( $this->rotator->begin() );
		self::assertFalse( $this->rotator->begin() );
	}
}
