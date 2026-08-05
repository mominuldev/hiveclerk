<?php
/**
 * Integration test base.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Loads a real WordPress so the parts that only exist inside one can be
 * tested: options, transients, the database, and the salts the encryptor
 * derives its key from.
 *
 * WordPress is located through the HIVECLERK_WP_ROOT environment variable,
 * falling back to walking up from the plugin directory — which is where it
 * is on any normal install.
 *
 * When WordPress cannot be found these tests skip rather than fail. A
 * contributor running the unit suite on a checkout with no database should
 * get a clean run and a clear note, not a wall of red that tells them
 * nothing about their change. CI sets the variable and therefore does run
 * them.
 */
abstract class WordPressTestCase extends TestCase {

	/**
	 * Whether WordPress has been loaded into this process.
	 *
	 * @var bool
	 */
	private static bool $loaded = false;

	/**
	 * Load WordPress once for the whole suite.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( self::$loaded || function_exists( 'get_option' ) ) {
			self::$loaded = true;

			return;
		}

		$root = self::locateWordPress();

		if ( null === $root ) {
			return;
		}

		// WordPress checks this to decide it is not serving a web request.
		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', false );
		}

		require_once $root . '/wp-load.php';

		self::$loaded = true;
	}

	/**
	 * Skip when WordPress is unavailable.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'get_option' ) ) {
			$this->markTestSkipped(
				'WordPress not found. Set HIVECLERK_WP_ROOT to a WordPress installation to run integration tests.'
			);
		}
	}

	/**
	 * Find a WordPress installation.
	 *
	 * @return string|null Absolute path, or null when not found.
	 */
	private static function locateWordPress(): ?string {
		$configured = getenv( 'HIVECLERK_WP_ROOT' );

		if ( is_string( $configured ) && is_file( rtrim( $configured, '/' ) . '/wp-load.php' ) ) {
			return rtrim( $configured, '/' );
		}

		// The plugin normally sits at wp-content/plugins/hiveclerk, so the
		// root is four levels up. Walk rather than assume, in case of a
		// custom content directory.
		$directory = dirname( __DIR__, 2 );

		for ( $depth = 0; $depth < 6; $depth++ ) {
			$directory = dirname( $directory );

			if ( is_file( $directory . '/wp-load.php' ) ) {
				return $directory;
			}
		}

		return null;
	}
}
