<?php
/**
 * Creating, changing and removing records through the API.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Integration;

use Hiveclerk\Database\Schema;

/**
 * The half of the REST surface that changes something.
 *
 * Reads are covered elsewhere. This is where a 422 matters — the point at
 * which a bad value either gets refused or gets stored — and it is also
 * where a careless test does damage, so every record here is created by
 * the test and removed by it.
 *
 * ## Removed properly, not politely
 *
 * `DELETE` on a clerk is a *soft* delete: it stamps `deleted_at` and the
 * row stays. That is right for the product — an operator who removes a
 * clerk has not asked for its conversation history to be destroyed — and
 * wrong for a test fixture, which would accumulate one invisible row per
 * run for ever. Tear-down removes the rows outright.
 *
 * Found by making the mess: an exploratory probe left three clerks on the
 * development site, one of them soft-deleted and therefore invisible to
 * the API that had just reported deleting it.
 *
 * @internal
 */
final class RestWritePathTest extends RestTestCase {

	/**
	 * Remove a clerk row entirely, whatever its soft-delete state.
	 *
	 * @param string $uuid Clerk uuid.
	 * @return void
	 */
	private function purgeAgent( string $uuid ): void {
		global $wpdb;

		$table = Schema::table( Schema::AGENTS );

		// The table name comes from Schema::table(), which validates against a
		// hard-coded allowlist and throws on anything else — the same reason
		// src/Database interpolates identifiers. Every value is still bound.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE uuid = %s", $uuid ) );
	}

	/**
	 * Remove a knowledge source row entirely.
	 *
	 * @param string $uuid Source uuid.
	 * @return void
	 */
	private function purgeSource( string $uuid ): void {
		global $wpdb;

		$table = Schema::table( Schema::KNOWLEDGE_SOURCES );

		// The table name comes from Schema::table(), which validates against a
		// hard-coded allowlist and throws on anything else — the same reason
		// src/Database interpolates identifiers. Every value is still bound.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE uuid = %s", $uuid ) );
	}

	/**
	 * Arrange to remove a record a refusal test did not expect to create.
	 *
	 * A test that asserts "this is refused" writes nothing on the happy
	 * path and therefore registers no cleanup — right up until the
	 * refusal stops happening, which is the one case it exists to catch.
	 * The assertion then fails *after* a real record has been written,
	 * with nothing arranged to remove it.
	 *
	 * Not hypothetical. Deleting the chunk validation to prove these tests
	 * catch its absence left a knowledge source on the development site,
	 * because the test failed before it ever got to a cleanup it did not
	 * have. Registering the undo before asserting is the fix: it costs
	 * nothing when the refusal works, and it is the difference between a
	 * failing test and a failing test that also made a mess.
	 *
	 * @param \WP_REST_Response $response Whatever came back.
	 * @param callable          $purge    Removes the record, given a uuid.
	 * @return void
	 */
	private function cleanUpIfItWasCreated( \WP_REST_Response $response, callable $purge ): void {
		if ( $response->get_status() >= 300 ) {
			return;
		}

		$data = (array) ( ( (array) $response->get_data() )['data'] ?? array() );

		if ( isset( $data['uuid'] ) && is_string( $data['uuid'] ) ) {
			$uuid = $data['uuid'];

			$this->afterwards( static fn() => $purge( $uuid ) );
		}
	}

	/**
	 * Create a clerk and arrange for it to be removed.
	 *
	 * @return array<string, mixed> The created clerk.
	 */
	private function createAgent(): array {
		$response = $this->request(
			'POST',
			'/admin/agents',
			array( 'name' => self::FIXTURE_PREFIX . 'clerk' )
		);

		$body = $this->assertOkEnvelope( $response, 'POST /admin/agents' );
		$data = (array) $body['data'];

		$this->assertArrayHasKey( 'uuid', $data, 'a created clerk came back without a uuid' );

		$uuid = (string) $data['uuid'];

		$this->afterwards( fn() => $this->purgeAgent( $uuid ) );

		return $data;
	}

	public function testCreatingAClerkAnswers201WithTheRecord(): void {
		$this->actAsAdministrator();

		$response = $this->request(
			'POST',
			'/admin/agents',
			array( 'name' => self::FIXTURE_PREFIX . 'clerk' )
		);

		$body = $this->assertOkEnvelope( $response, 'POST /admin/agents' );
		$uuid = (string) ( (array) $body['data'] )['uuid'];

		$this->afterwards( fn() => $this->purgeAgent( $uuid ) );

		$this->assertSame( 201, $response->get_status(), 'creation should answer 201, not 200' );
		$this->assertSame( self::FIXTURE_PREFIX . 'clerk', ( (array) $body['data'] )['name'] );
	}

	/**
	 * A new clerk is a draft, so nothing a customer built reaches a
	 * visitor before somebody decided it should.
	 */
	public function testANewClerkIsNotLive(): void {
		$this->actAsAdministrator();

		$agent = $this->createAgent();

		$this->assertSame( 'draft', $agent['status'] );
	}

	public function testACreatedClerkCanBeReadBack(): void {
		$this->actAsAdministrator();

		$agent = $this->createAgent();
		$body  = $this->assertOkEnvelope(
			$this->request( 'GET', '/admin/agents/' . $agent['uuid'] ),
			'GET the clerk just created'
		);

		$this->assertSame( $agent['uuid'], ( (array) $body['data'] )['uuid'] );
	}

	public function testAClerkCanBeRenamed(): void {
		$this->actAsAdministrator();

		$agent = $this->createAgent();

		$this->assertOkEnvelope(
			$this->request(
				'PATCH',
				'/admin/agents/' . $agent['uuid'],
				array( 'name' => self::FIXTURE_PREFIX . 'renamed' )
			),
			'PATCH the clerk'
		);

		$body = $this->assertOkEnvelope(
			$this->request( 'GET', '/admin/agents/' . $agent['uuid'] ),
			'GET after PATCH'
		);

		$this->assertSame( self::FIXTURE_PREFIX . 'renamed', ( (array) $body['data'] )['name'] );
	}

	/**
	 * Deleting hides it from the API, which is what the operator asked for.
	 */
	public function testADeletedClerkIsGoneFromTheApi(): void {
		$this->actAsAdministrator();

		$agent = $this->createAgent();

		$deleted = $this->request( 'DELETE', '/admin/agents/' . $agent['uuid'] );

		$this->assertLessThan( 300, $deleted->get_status(), 'DELETE answered ' . $deleted->get_status() );

		$this->assertErrorStatus(
			$this->request( 'GET', '/admin/agents/' . $agent['uuid'] ),
			404,
			'GET a deleted clerk'
		);
	}

	/**
	 * The cost-exhaustion door, through the real dispatcher.
	 *
	 * A unit test covers this against a hand-built request; this is the
	 * same refusal with WordPress's argument handling in front of it,
	 * which is the arrangement a customer's browser actually meets.
	 */
	public function testAChunkTargetThatWouldRunUpABillIsRefused(): void {
		$this->actAsAdministrator();

		$response = $this->request(
			'POST',
			'/admin/knowledge/sources',
			array(
				'type'   => 'faq',
				'name'   => self::FIXTURE_PREFIX . 'source',
				'config' => array( 'chunk_target' => 1 ),
			)
		);

		$this->cleanUpIfItWasCreated( $response, fn( string $uuid ) => $this->purgeSource( $uuid ) );

		$code = $this->assertErrorStatus( $response, 422, 'POST a source with chunk_target=1' );

		$this->assertSame( 'hvc_validation_failed', $code );
		$this->assertStringContainsString( 'embedding you pay for', $this->messageOf( $response ) );
	}

	public function testAnUnknownSourceTypeIsRefused(): void {
		$this->actAsAdministrator();

		$response = $this->request(
			'POST',
			'/admin/knowledge/sources',
			array(
				'type' => 'not-a-source-type',
				'name' => self::FIXTURE_PREFIX . 'source',
			)
		);

		$this->cleanUpIfItWasCreated( $response, fn( string $uuid ) => $this->purgeSource( $uuid ) );

		$this->assertErrorStatus( $response, 422, 'POST a source with an unknown type' );
	}

	/**
	 * And a sound configuration is accepted, or the refusals above prove
	 * only that the endpoint refuses everything.
	 */
	public function testASoundSourceConfigurationIsAccepted(): void {
		$this->actAsAdministrator();

		$response = $this->request(
			'POST',
			'/admin/knowledge/sources',
			array(
				'type'   => 'faq',
				'name'   => self::FIXTURE_PREFIX . 'source',
				'config' => array(
					'chunk_tokens' => 800,
					'chunk_target' => 200,
				),
			)
		);

		$body = $this->assertOkEnvelope( $response, 'POST a valid source' );
		$uuid = (string) ( (array) $body['data'] )['uuid'];

		$this->afterwards( fn() => $this->purgeSource( $uuid ) );

		$this->assertNotSame( '', $uuid );
	}

	/**
	 * A write is refused for somebody who may only read.
	 *
	 * The read tests establish that a subscriber reaches nothing at all.
	 * This is the narrower and more interesting case: the capability split
	 * that lets a shop manager supervise conversations without being able
	 * to reconfigure the product.
	 */
	public function testAUserWithoutTheCapabilityCannotCreateAClerk(): void {
		$this->actAsNewUser( 'subscriber' );

		$response = $this->request(
			'POST',
			'/admin/agents',
			array( 'name' => self::FIXTURE_PREFIX . 'should-not-exist' )
		);

		$this->cleanUpIfItWasCreated( $response, fn( string $uuid ) => $this->purgeAgent( $uuid ) );

		$this->assertErrorStatus( $response, 403, 'POST /admin/agents as a subscriber' );

		// And nothing was written on the way to being refused.
		global $wpdb;

		$table = Schema::table( Schema::AGENTS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE name = %s", self::FIXTURE_PREFIX . 'should-not-exist' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $sql );

		$this->assertSame( 0, (int) $found, 'a refused create still wrote a row' );
	}
}
