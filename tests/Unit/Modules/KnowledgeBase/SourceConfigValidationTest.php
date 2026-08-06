<?php
/**
 * Chunk-configuration validation at the REST boundary.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Modules\KnowledgeBase;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Hiveclerk\Domain\Knowledge\SourceType;
use Hiveclerk\Modules\KnowledgeBase\Extractors\ExtractorRegistry;
use Hiveclerk\Modules\KnowledgeBase\Http\SourceController;
use Hiveclerk\Modules\KnowledgeBase\Text\ChunkOptions;
use Hiveclerk\Tests\Support\Knowledge\AlwaysAvailableExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WP_Error;
use WP_REST_Request;

/**
 * The door that refuses a request to spend the customer's money.
 *
 * A chunk target divides a page into chunks and every chunk is an
 * embedding call on the customer's provider account. `chunk_target: 1`
 * turns one page into roughly a chunk per sentence, and
 * `SourceController::clean()` passes arbitrary keys through, so it was
 * reachable over REST by anyone holding `manage_knowledge` — a
 * capability roles that are deliberately never given the API key still
 * have. SEC-03 calls cost exhaustion cheaper to execute than a denial of
 * service, and this was a cheap one.
 *
 * Two defences exist and they are not the same. `ChunkOptions::fromConfig()`
 * clamps, because it reads configuration written by older versions and
 * cannot refuse it retrospectively. This one refuses, because at a door
 * clamping tells an operator their setting was accepted when it was not.
 * The clamp is tested in `ChunkerServiceTest`; this is the other half,
 * and it was previously verified only by a live probe against a running
 * site — which proves it works today and would not notice it being
 * removed.
 *
 * ## Why the controller is built without its constructor
 *
 * It takes eleven collaborators. The path under test needs exactly one:
 * `create()` refuses an unknown or unavailable extractor before anything
 * else, then validates the configuration and returns. Nothing between
 * those two points touches storage, the queue or the audit log. Building
 * the other ten would be a fixture larger than the behaviour, and every
 * one of them would be a fake nobody reads.
 *
 * @internal
 */
#[CoversClass( SourceController::class )]
final class SourceConfigValidationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The vector itself.
	 */
	public function testAChunkTargetOfOneIsRefused(): void {
		$error = $this->create( array( 'chunk_target' => 1 ) );

		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 422, $error->get_error_data()['status'] ?? 0 );
		self::assertStringContainsString( 'embedding you pay for', $error->get_error_message() );
	}

	/**
	 * Anything below the floor, not just the pathological value.
	 */
	public function testAChunkTargetBelowTheFloorIsRefused(): void {
		$error = $this->create( array( 'chunk_target' => ChunkOptions::MIN_TARGET_TOKENS - 1 ) );

		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 422, $error->get_error_data()['status'] ?? 0 );
	}

	/**
	 * A target above the size a chunk may reach is not a target.
	 */
	public function testAChunkTargetAboveTheDeclaredChunkSizeIsRefused(): void {
		$error = $this->create(
			array(
				'chunk_tokens' => 200,
				'chunk_target' => 400,
			)
		);

		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 422, $error->get_error_data()['status'] ?? 0 );
	}

	public function testAChunkSizeOutsideTheAcceptedRangeIsRefused(): void {
		foreach ( array( 1, ChunkOptions::ABSOLUTE_MAX_TOKENS + 1 ) as $value ) {
			$error = $this->create( array( 'chunk_tokens' => $value ) );

			self::assertInstanceOf( WP_Error::class, $error, 'chunk_tokens ' . $value . ' was accepted' );
			self::assertSame( 422, $error->get_error_data()['status'] ?? 0 );
		}
	}

	/**
	 * Past half a chunk every passage is stored and embedded twice.
	 */
	public function testAnOverlapAboveAHalfIsRefused(): void {
		$error = $this->create( array( 'chunk_overlap' => 0.95 ) );

		self::assertInstanceOf( WP_Error::class, $error );
		self::assertStringContainsString( 'stored and embedded twice', $error->get_error_message() );
	}

	/**
	 * A value that is not a number at all is not silently cast.
	 */
	public function testANonNumericChunkSettingIsRefused(): void {
		$error = $this->create( array( 'chunk_target' => 'lots' ) );

		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 422, $error->get_error_data()['status'] ?? 0 );
	}

	/**
	 * The refusal must not be so eager that it blocks honest use.
	 *
	 * A test that only proves things are refused would pass just as well
	 * against a door that refuses everything.
	 */
	public function testAValidConfigurationIsNotRefused(): void {
		self::assertNull(
			$this->validationError(
				array(
					'chunk_tokens'  => 800,
					'chunk_target'  => 200,
					'chunk_overlap' => 0.15,
				)
			)
		);
	}

	/**
	 * A source with no chunk settings at all is the common case.
	 */
	public function testAConfigurationWithNoChunkSettingsIsNotRefused(): void {
		self::assertNull( $this->validationError( array( 'post_types' => array( 'page' ) ) ) );
	}

	/**
	 * Run create() far enough to reach the configuration check.
	 *
	 * @param array<string, mixed> $config Source configuration.
	 * @return WP_Error|null The refusal, or null when it got past it.
	 */
	private function create( array $config ): ?WP_Error {
		return $this->validationError( $config );
	}

	/**
	 * The validation outcome for a configuration.
	 *
	 * Returns null when the configuration was accepted — at which point
	 * `create()` goes on to touch storage, which this fixture deliberately
	 * has not provided, so the call is stopped there.
	 *
	 * @param array<string, mixed> $config Source configuration.
	 * @return WP_Error|null
	 */
	private function validationError( array $config ): ?WP_Error {
		$registry = new ExtractorRegistry();
		$registry->add( new AlwaysAvailableExtractor( SourceType::Faq ) );

		$reflection = new ReflectionClass( SourceController::class );
		$controller = $reflection->newInstanceWithoutConstructor();

		$extractors = $reflection->getProperty( 'extractors' );
		$extractors->setValue( $controller, $registry );

		$request = new WP_REST_Request(
			'POST',
			'/admin/knowledge/sources',
			array(
				'type'   => SourceType::Faq->value,
				'name'   => 'Probe',
				'config' => $config,
			)
		);

		try {
			$result = $controller->create( $request );
		} catch ( \Throwable $e ) {
			/*
			 * Past the validation and into storage, which this fixture
			 * deliberately does not provide.
			 *
			 * Asserted rather than assumed. Swallowing any throwable here
			 * would turn "threw before it ever reached the validation"
			 * into "the configuration was accepted", and every
			 * not-refused test below would pass against a controller that
			 * had stopped validating anything at all.
			 */
			self::assertStringContainsString(
				'$sources must not be accessed',
				$e->getMessage(),
				'create() stopped before reaching the configuration check, so this '
					. 'test proved nothing about the configuration'
			);

			return null;
		}

		return $result instanceof WP_Error ? $result : null;
	}
}
