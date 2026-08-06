<?php
/**
 * The version is declared in four places and must not drift.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Four declarations, one version.
 *
 * The plugin header is what WordPress shows and what the update check
 * compares. `HIVECLERK_VERSION` is what the code reads — asset cache
 * busting, the licence request, the audit log. `package.json` is what the
 * front-end toolchain stamps. They are written by hand in three files and
 * nothing has ever compared them.
 *
 * The failure is quiet in the way that matters: bumping the header and
 * forgetting the constant ships a release whose assets are served from
 * the previous version's cache, and whose licence check reports a version
 * the customer is not running. Nothing errors.
 *
 * `readme.txt`'s stable tag is a different kind of statement — it names
 * the version WordPress.org should serve, which is not necessarily the
 * one in development. It said `0.1.0` while every other declaration said
 * `0.1.0-dev`, which claimed a released 0.1.0 that has never existed.
 *
 * @internal
 */
final class VersionConsistencyTest extends TestCase {

	/**
	 * Plugin root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The version in the plugin header.
	 *
	 * @return string
	 */
	private function headerVersion(): string {
		$source = (string) file_get_contents( $this->root() . '/hiveclerk.php' );

		preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $source, $matched );

		return trim( $matched[1] ?? '' );
	}

	/**
	 * The version the code reads.
	 *
	 * @return string
	 */
	private function constantVersion(): string {
		$source = (string) file_get_contents( $this->root() . '/hiveclerk.php' );

		preg_match( "/define\(\s*'HIVECLERK_VERSION',\s*'([^']+)'/", $source, $matched );

		return trim( $matched[1] ?? '' );
	}

	/**
	 * The version the front-end toolchain stamps.
	 *
	 * @return string
	 */
	private function packageVersion(): string {
		$package = json_decode( (string) file_get_contents( $this->root() . '/package.json' ), true );

		return is_array( $package ) ? (string) ( $package['version'] ?? '' ) : '';
	}

	/**
	 * The stable tag WordPress.org is told to serve.
	 *
	 * @return string
	 */
	private function stableTag(): string {
		$readme = (string) file_get_contents( $this->root() . '/readme.txt' );

		preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $matched );

		return trim( $matched[1] ?? '' );
	}

	public function testTheVersionIsDeclaredEverywhereItIsNeeded(): void {
		self::assertNotSame( '', $this->headerVersion(), 'the plugin header has no Version' );
		self::assertNotSame( '', $this->constantVersion(), 'HIVECLERK_VERSION is not defined' );
		self::assertNotSame( '', $this->packageVersion(), 'package.json has no version' );
		self::assertNotSame( '', $this->stableTag(), 'readme.txt has no Stable tag' );
	}

	/**
	 * The header and the constant are read by different things about the
	 * same install, so they cannot disagree.
	 */
	public function testTheHeaderAndTheConstantAgree(): void {
		self::assertSame(
			$this->headerVersion(),
			$this->constantVersion(),
			'the plugin header and HIVECLERK_VERSION disagree — assets would be cached '
				. 'against one version while the licence check reports the other'
		);
	}

	public function testTheFrontEndToolchainStampsTheSameVersion(): void {
		self::assertSame(
			$this->headerVersion(),
			$this->packageVersion(),
			'package.json and the plugin header disagree'
		);
	}

	/**
	 * A stable tag names a release, or names trunk.
	 *
	 * WordPress.org serves whatever this points at. `trunk` is the
	 * documented value for a plugin whose stable version is simply what is
	 * committed, which is the honest answer while nothing has been
	 * released — and it is the only answer that does not name a version
	 * that may not exist.
	 */
	public function testTheStableTagNamesTrunkOrTheCurrentVersion(): void {
		$tag = $this->stableTag();

		self::assertTrue(
			'trunk' === $tag || $tag === $this->headerVersion(),
			sprintf(
				'readme.txt claims stable tag "%s" while the plugin is version "%s". A stable tag '
					. 'naming a version that was never released points WordPress.org at nothing; '
					. 'use "trunk" until there is a release to name.',
				$tag,
				$this->headerVersion()
			)
		);
	}
}
