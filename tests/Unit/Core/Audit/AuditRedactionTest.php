<?php
/**
 * Audit redaction tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Audit;

use Hiveclerk\Core\Audit\AuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The audit log records changes to the screen that holds the customer's
 * API key, which makes writing that key into the log the single most
 * likely way to leak it. These tests are the guard on that.
 *
 * @internal
 */
#[CoversClass( AuditLogger::class )]
final class AuditRedactionTest extends TestCase {

	/**
	 * @param string $field Field name that should be treated as a secret.
	 */
	#[DataProvider( 'secretFieldNames' )]
	public function testRedactsSecretLookingFields( string $field ): void {
		$clean = AuditLogger::redact( array( $field => 'sk-ant-api03-realvalue' ) );

		$this->assertSame( '[redacted]', $clean[ $field ] );
	}

	/**
	 * Field names every integration in the roadmap will produce.
	 *
	 * @return array<int, array<int, string>>
	 */
	public static function secretFieldNames(): array {
		return array(
			array( 'api_key' ),
			array( 'apiKey' ),
			array( 'key' ),
			array( 'client_secret' ),
			array( 'refresh_token' ),
			array( 'access_token' ),
			array( 'password' ),
			array( 'Authorization' ),
			array( 'webhook_signature' ),
			array( 'aws_credential' ),
		);
	}

	public function testKeepsTheFieldSoTheChangeIsStillVisible(): void {
		$clean = AuditLogger::redact( array( 'api_key' => 'secret' ) );

		// Knowing that a key changed is the entire point of the record.
		// Dropping the field would make the log useless; keeping the value
		// would make it dangerous.
		$this->assertArrayHasKey( 'api_key', $clean );
	}

	public function testLeavesOrdinaryFieldsAlone(): void {
		$clean = AuditLogger::redact(
			array(
				'provider' => 'anthropic',
				'model'    => 'claude-sonnet-4-5',
				'endpoint' => 'https://example.test',
			)
		);

		$this->assertSame( 'anthropic', $clean['provider'] );
		$this->assertSame( 'claude-sonnet-4-5', $clean['model'] );
		$this->assertSame( 'https://example.test', $clean['endpoint'] );
	}

	public function testRedactsInsideNestedArrays(): void {
		$clean = AuditLogger::redact(
			array(
				'integration' => array(
					'name'        => 'hubspot',
					'credentials' => array(
						'access_token' => 'pat-na1-secret',
						'portal_id'    => 12345,
					),
				),
			)
		);

		$this->assertSame( '[redacted]', $clean['integration']['credentials']['access_token'] );
		$this->assertSame( 12345, $clean['integration']['credentials']['portal_id'] );
		$this->assertSame( 'hubspot', $clean['integration']['name'] );
	}

	public function testLeavesBooleansAndNullsIntact(): void {
		$clean = AuditLogger::redact(
			array(
				'key_changed' => true,
				'api_key'     => null,
			)
		);

		// "The key was cleared" is information worth logging and is not
		// itself a secret.
		$this->assertTrue( $clean['key_changed'] );
		$this->assertNull( $clean['api_key'] );
	}

	public function testAnEmptySecretIsNotMarkedRedacted(): void {
		$clean = AuditLogger::redact( array( 'api_key' => '' ) );

		// Reporting "[redacted]" for a blank value would claim a secret
		// was set when none was.
		$this->assertSame( '', $clean['api_key'] );
	}
}
