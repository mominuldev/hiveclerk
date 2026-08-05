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
