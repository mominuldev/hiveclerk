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

/*
 * A minimal WP_Error.
 *
 * Brain Monkey stubs functions, not classes, and ApiResponse::error()
 * returns a real WP_Error — so any unit test that walks a refusal path
 * needs the class to exist. Only the surface this codebase touches is
 * implemented; anything else should fail loudly rather than pretend.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace
	class WP_Error {

		/**
		 * Messages by code.
		 *
		 * @var array<string, array<int, string>>
		 */
		public array $errors = array();

		/**
		 * Data by code.
		 *
		 * @var array<string, mixed>
		 */
		public array $error_data = array(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

		/**
		 * Construct.
		 *
		 * @param string $code    Error code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			if ( '' === $code ) {
				return;
			}

			$this->errors[ $code ][] = $message;

			if ( null !== $data ) {
				$this->error_data[ $code ] = $data; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
			}
		}

		/**
		 * The first error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			$codes = array_keys( $this->errors );

			return (string) ( $codes[0] ?? '' );
		}

		/**
		 * The first message for a code.
		 *
		 * @param string $code Error code, or empty for the first.
		 * @return string
		 */
		public function get_error_message( string $code = '' ): string {
			$code = '' === $code ? $this->get_error_code() : $code;

			return (string) ( $this->errors[ $code ][0] ?? '' );
		}

		/**
		 * The data attached to a code.
		 *
		 * @param string $code Error code, or empty for the first.
		 * @return mixed
		 */
		public function get_error_data( string $code = '' ): mixed {
			$code = '' === $code ? $this->get_error_code() : $code;

			return $this->error_data[ $code ] ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
		}

		/**
		 * Merge more data into a code.
		 *
		 * @param mixed  $data Data.
		 * @param string $code Error code, or empty for the first.
		 * @return void
		 */
		public function add_data( mixed $data, string $code = '' ): void {
			$code = '' === $code ? $this->get_error_code() : $code;

			$this->error_data[ $code ] = $data; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
		}
	}
}
