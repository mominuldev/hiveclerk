<?php
/**
 * The contract every background job is held to.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Queue;

use Hiveclerk\Core\Queue\AbstractJob;
use Hiveclerk\Core\Queue\JobInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Discovers the jobs rather than listing them.
 *
 * A list would pass forever after somebody adds the ninth job and forgets
 * to add it here, which is the failure this is supposed to catch. The
 * filesystem is the source of truth, so a new job is in the suite the
 * moment it exists.
 *
 * What is asserted is the part of CLAUDE.md's background-work rule that a
 * type signature cannot express: a job declares its own hook, the hook
 * carries the prefix the deactivation sweep and the status screen both
 * match on, and any batch size it declares is a real bound rather than a
 * number that happens to be large.
 *
 * The prefix matters more than it looks. `Deactivator` unschedules by
 * prefix and `Footprint` sweeps by prefix; a job whose hook does not
 * carry it keeps firing after the plugin is deactivated, at a hook with
 * no listener, rescheduling itself forever. That happened — three jobs
 * survived deactivation for several sprints, and nothing errored, which
 * is why it lasted.
 *
 * @internal
 */
final class JobContractTest extends TestCase {

	/**
	 * Prefix every job hook must carry.
	 *
	 * Deliberately just `hiveclerk/`: the codebase uses both
	 * `hiveclerk/jobs/…` and `hiveclerk/job/…`, and the sweeps match the
	 * shorter one. Asserting the longer form would fail honest code.
	 */
	private const REQUIRED_PREFIX = 'hiveclerk/';

	/**
	 * Largest batch a job may claim to process in one run.
	 *
	 * Jobs hold themselves to roughly twenty seconds. This is not a
	 * measurement of that — it is a ceiling loose enough never to argue
	 * with a considered value and tight enough to catch a missing bound
	 * or a units mistake.
	 */
	private const MAX_REASONABLE_BATCH = 5000;

	/**
	 * Every concrete job in the codebase.
	 *
	 * @return array<string, array{0: class-string<JobInterface>}>
	 */
	public static function jobs(): array {
		$root  = dirname( __DIR__, 4 );
		$cases = array();

		$files = new \RegexIterator(
			new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root . '/src' )
			),
			'/Job\.php$/'
		);

		foreach ( $files as $file ) {
			$path = (string) $file;

			if ( str_ends_with( $path, 'AbstractJob.php' ) ) {
				continue;
			}

			$jobClass = self::classFor( $path );

			if ( null !== $jobClass && is_subclass_of( $jobClass, JobInterface::class ) ) {
				$cases[ $jobClass ] = array( $jobClass );
			}
		}

		return $cases;
	}

	/**
	 * There are jobs to check, so a broken discovery cannot pass silently.
	 */
	public function testTheJobsAreActuallyDiscovered(): void {
		self::assertGreaterThanOrEqual(
			8,
			count( self::jobs() ),
			'job discovery found fewer classes than the codebase has'
		);
	}

	/**
	 * @param class-string<JobInterface> $jobClass Job.
	 */
	#[DataProvider( 'jobs' )]
	public function testAJobDeclaresItsOwnHook( string $jobClass ): void {
		$hook = $jobClass::hook();

		self::assertNotSame( '', $hook, $jobClass . ' has an empty hook' );
		self::assertStringStartsWith(
			self::REQUIRED_PREFIX,
			$hook,
			$jobClass . ' has a hook the deactivation sweep will not match, so it '
				. 'would keep firing after the plugin is switched off'
		);
	}

	/**
	 * @param class-string<JobInterface> $jobClass Job.
	 */
	#[DataProvider( 'jobs' )]
	public function testAJobExtendsTheBaseClassThatGuardsItsArguments( string $jobClass ): void {
		// Arguments arrive from storage, serialised possibly weeks ago by
		// a previous version. AbstractJob is where the type-guarded readers
		// live, and a job that parses its own arguments is a job that
		// fatals on the sites that upgraded mid-queue.
		self::assertTrue(
			is_subclass_of( $jobClass, AbstractJob::class ),
			$jobClass . ' does not extend AbstractJob'
		);
	}

	/**
	 * @param class-string<JobInterface> $jobClass Job.
	 */
	#[DataProvider( 'jobs' )]
	public function testAnyDeclaredBatchSizeIsABound( string $jobClass ): void {
		$constants = ( new ReflectionClass( $jobClass ) )->getConstants();
		$batches   = array();

		foreach ( $constants as $name => $value ) {
			if ( preg_match( '/BATCH|PER_RUN|MAX_PASSES|LIMIT/', $name ) && is_int( $value ) ) {
				$batches[ $name ] = $value;
			}
		}

		if ( array() === $batches ) {
			// Not every job processes a batch: the single-item ones —
			// syncing one lead, delivering one webhook — have nothing to
			// bound. Absence is only a fault when there is a loop.
			$this->expectNotToPerformAssertions();

			return;
		}

		foreach ( $batches as $name => $value ) {
			self::assertGreaterThan( 0, $value, $jobClass . '::' . $name . ' is not a bound' );
			self::assertLessThanOrEqual(
				self::MAX_REASONABLE_BATCH,
				$value,
				$jobClass . '::' . $name . ' is large enough that one run is unlikely to '
					. 'finish inside the execution limit'
			);
		}
	}

	/**
	 * Two jobs on one hook means one of them silently never runs.
	 */
	public function testNoTwoJobsShareAHook(): void {
		$hooks = array();

		foreach ( array_keys( self::jobs() ) as $jobClass ) {
			$hook = $jobClass::hook();

			self::assertArrayNotHasKey(
				$hook,
				$hooks,
				$hook . ' is claimed by both ' . ( $hooks[ $hook ] ?? '' ) . ' and ' . $jobClass
			);

			$hooks[ $hook ] = $jobClass;
		}

		self::assertNotSame( array(), $hooks );
	}

	/**
	 * A recurring job's interval must be a real cadence.
	 *
	 * `CronQueue` maps an interval to the nearest schedule it registered,
	 * so a zero or negative one does not fail — it quietly becomes the
	 * shortest available and runs every minute for ever.
	 */
	public function testARecurringIntervalIsPositive(): void {
		foreach ( array_keys( self::jobs() ) as $jobClass ) {
			$constants = ( new ReflectionClass( $jobClass ) )->getConstants();

			if ( ! isset( $constants['INTERVAL'] ) ) {
				continue;
			}

			self::assertIsInt( $constants['INTERVAL'], $jobClass . '::INTERVAL is not a number of seconds' );
			self::assertGreaterThanOrEqual(
				60,
				$constants['INTERVAL'],
				$jobClass . '::INTERVAL is shorter than the shortest schedule WP-Cron has'
			);
		}
	}

	/**
	 * Fully-qualified class name for a source file.
	 *
	 * Read from the file rather than guessed from the path, because the
	 * namespace is what the autoloader uses and a mismatch between the two
	 * is itself worth failing on.
	 *
	 * @param string $path Absolute file path.
	 * @return class-string|null
	 */
	private static function classFor( string $path ): ?string {
		$source = (string) file_get_contents( $path );

		if ( ! preg_match( '/^namespace\s+([^;]+);/m', $source, $namespace ) ) {
			return null;
		}

		if ( ! preg_match( '/^final class\s+(\w+)/m', $source, $matched ) ) {
			return null;
		}

		$name = trim( $namespace[1] ) . '\\' . $matched[1];

		return class_exists( $name ) ? $name : null;
	}
}
