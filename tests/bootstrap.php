<?php
/**
 * PHPUnit bootstrap.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'HIVECLERK_VERSION' ) ) {
	define( 'HIVECLERK_VERSION', '0.1.0-dev' );
	define( 'HIVECLERK_DIR', dirname( __DIR__ ) . '/' );
	define( 'HIVECLERK_URL', 'https://example.test/wp-content/plugins/hiveclerk/' );
	define( 'HIVECLERK_BASENAME', 'hiveclerk/hiveclerk.php' );
	define( 'HIVECLERK_MIN_PHP', '8.3' );
	define( 'HIVECLERK_MIN_WP', '6.6' );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

/*
 * WordPress's security salts. Fixed values, not random ones: several
 * classes derive a key from these, and a test that wants to assert two
 * calls produced the same signature needs them stable across the run.
 * Every real install defines all three in wp-config.php.
 */
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'hiveclerk-test-auth-key' );
	define( 'SECURE_AUTH_KEY', 'hiveclerk-test-secure-auth-key' );
	define( 'AUTH_SALT', 'hiveclerk-test-auth-salt' );
}

/*
 * WordPress's time constants. Several classes use them in class-constant
 * expressions, which PHP evaluates on first access rather than at load —
 * so a unit test that only touches the arithmetic still needs them
 * defined, and defining them is cheaper than restructuring the constants
 * to hide a dependency the production code genuinely has.
 */
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WEEK_IN_SECONDS', 604800 );
}
