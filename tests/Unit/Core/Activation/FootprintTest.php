<?php
/**
 * Footprint drift tests.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit\Core\Activation;

use Hiveclerk\Core\Activation\Footprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the uninstall list still matches the code.
 *
 * This test reads the source rather than calling anything, which is
 * unusual and is the entire point. The bug it exists to prevent is not a
 * logic error — every function involved worked perfectly — it is a list
 * that stopped describing the codebase around it. By Sprint 10 the
 * uninstall routine named an option that had never existed
 * (`hiveclerk_licence`) and omitted five that did, including the row
 * holding the customer's encrypted model API keys. No unit test of any
 * behaviour could have failed on that, because no behaviour was wrong.
 *
 * Both directions are checked. Missing an option leaves customer data on
 * a site that asked for it to be gone; keeping a stale one is how the
 * list stops being trustworthy, and a list nobody trusts is one nobody
 * reads before adding the next option.
 *
 * @internal
 */
#[CoversClass( Footprint::class )]
final class FootprintTest extends TestCase {

	/**
	 * Options that are deliberately in the list with no code behind them.
	 *
	 * Removed from the product but not from the installs that already ran
	 * it, so uninstall still has to clean them up. Each one needs the
	 * sprint that orphaned it recorded, because otherwise this array is
	 * where the next stale entry hides.
	 *
	 * @var array<string, string>
	 */
	private const LEGACY = array(
		// Written by Activator until Sprint 10; OnboardingState (Sprint 9)
		// owns `hiveclerk_onboarding` and never read this one.
		'hiveclerk_onboarding_state' => 'Sprint 9',
	);

	/**
	 * Every option the source writes is in the uninstall list.
	 *
	 * @return void
	 */
	public function testEveryOptionInTheSourceIsInTheFootprint(): void {
		$declared = Footprint::options();

		foreach ( $this->optionsUsedInSource() as $option => $where ) {
			$this->assertContains(
				$option,
				$declared,
				sprintf(
					'%s is written by %s but Footprint::options() does not list it, '
					. 'so an opted-in uninstall would leave the row behind.',
					$option,
					$where
				)
			);
		}
	}

	/**
	 * Every option in the uninstall list is still real.
	 *
	 * @return void
	 */
	public function testEveryFootprintOptionExistsInTheSource(): void {
		$used = $this->optionsUsedInSource();

		foreach ( Footprint::options() as $option ) {
			if ( isset( self::LEGACY[ $option ] ) ) {
				continue;
			}

			$this->assertArrayHasKey(
				$option,
				$used,
				sprintf(
					'Footprint::options() lists %s but nothing in src/ writes it. '
					. 'Either it was renamed — in which case the new name is missing '
					. 'too — or it is orphaned and belongs in self::LEGACY with the '
					. 'sprint that retired it.',
					$option
				)
			);
		}
	}

	/**
	 * Hooks are swept by prefix, so every job hook must carry it.
	 *
	 * @return void
	 */
	public function testEveryJobHookCarriesTheSweptPrefix(): void {
		$hooks = array();

		foreach ( $this->sourceFiles() as $file ) {
			$contents = (string) file_get_contents( $file );

			if ( ! str_contains( $contents, 'function hook()' ) ) {
				continue;
			}

			if ( preg_match_all( "/return\s+'([^']+)';/", $contents, $matches ) > 0 ) {
				foreach ( $matches[1] as $candidate ) {
					if ( str_contains( $candidate, '/job' ) ) {
						$hooks[ $candidate ] = $file;
					}
				}
			}
		}

		$this->assertNotSame( array(), $hooks, 'No job hooks were found to check.' );

		foreach ( $hooks as $hook => $file ) {
			$this->assertStringStartsWith(
				Footprint::HOOK_PREFIX,
				$hook,
				sprintf(
					'%s declares the hook %s, which the prefix sweep in '
					. 'Footprint::unscheduleAll() would not find. It would survive '
					. 'deactivation and keep firing at a hook with no listener.',
					basename( $file ),
					$hook
				)
			);
		}
	}

	/**
	 * Option names the source actually uses, mapped to where.
	 *
	 * Two idioms are in play and both are found here. Most classes hold
	 * the name in a constant whose name ends in `OPTION`; a handful of
	 * lifecycle functions pass the literal straight to `add_option()`.
	 * Matching only one of the two would make this test pass while the
	 * gap it exists to catch was open.
	 *
	 * @return array<string, string>
	 */
	private function optionsUsedInSource(): array {
		$found = array();

		foreach ( $this->sourceFiles() as $file ) {
			$contents = (string) file_get_contents( $file );
			$where    = basename( $file );

			// `private const SALT_OPTION = 'hiveclerk_encryption_salt';`
			if ( preg_match_all( "/const\s+\w*OPTION\s*=\s*'(hiveclerk_[a-z_]+)'/", $contents, $matches ) > 0 ) {
				foreach ( $matches[1] as $option ) {
					$found[ $option ] = $where;
				}
			}

			// `add_option( 'hiveclerk_installed_at', … )`
			if ( preg_match_all( "/(?:get|add|update|delete)_option\(\s*'(hiveclerk_[a-z_]+)'/", $contents, $matches ) > 0 ) {
				foreach ( $matches[1] as $option ) {
					$found[ $option ] = $where;
				}
			}
		}

		return $found;
	}

	/**
	 * Every PHP file under src/.
	 *
	 * @return array<int, string>
	 */
	private function sourceFiles(): array {
		$root  = dirname( __DIR__, 4 ) . '/src';
		$files = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );

		return $files;
	}
}
