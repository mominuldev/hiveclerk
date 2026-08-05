<?php
/**
 * Plugin Name:       Hiveclerk
 * Plugin URI:        https://hiveclerk.com
 * Description:       A hive of AI clerks for your website. Deploy AI employees that talk to visitors, qualify leads and answer support questions.
 * Version:           0.1.0-dev
 * Requires at least: 6.6
 * Requires PHP:      8.3
 * Author:            Decent Themes
 * Author URI:        https://decenthemes.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hiveclerk
 * Domain Path:       /languages
 *
 * @package Hiveclerk
 */

/*
 * IMPORTANT: This file must stay parseable by PHP 5.6.
 *
 * If a site running an unsupported PHP version activates the plugin, WordPress
 * parses this file before any version check can run. Modern syntax here would
 * produce a white-screen parse error instead of the readable admin notice
 * below. Everything requiring PHP 8.3 lives behind the guard in bootstrap().
 */

defined( 'ABSPATH' ) || exit;

define( 'HIVECLERK_VERSION', '0.1.0-dev' );
define( 'HIVECLERK_FILE', __FILE__ );
define( 'HIVECLERK_DIR', plugin_dir_path( __FILE__ ) );
define( 'HIVECLERK_URL', plugin_dir_url( __FILE__ ) );
define( 'HIVECLERK_BASENAME', plugin_basename( __FILE__ ) );
define( 'HIVECLERK_MIN_PHP', '8.3' );
define( 'HIVECLERK_MIN_WP', '6.6' );

/**
 * Collect unmet environment requirements.
 *
 * @return array List of human-readable requirement failures.
 */
function hiveclerk_unmet_requirements() {
	$problems = array();

	if ( version_compare( PHP_VERSION, HIVECLERK_MIN_PHP, '<' ) ) {
		$problems[] = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			__( 'Hiveclerk needs PHP %1$s or newer. This site runs PHP %2$s.', 'hiveclerk' ),
			HIVECLERK_MIN_PHP,
			PHP_VERSION
		);
	}

	if ( version_compare( get_bloginfo( 'version' ), HIVECLERK_MIN_WP, '<' ) ) {
		$problems[] = sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version */
			__( 'Hiveclerk needs WordPress %1$s or newer. This site runs WordPress %2$s.', 'hiveclerk' ),
			HIVECLERK_MIN_WP,
			get_bloginfo( 'version' )
		);
	}

	if ( ! file_exists( HIVECLERK_DIR . 'vendor/autoload.php' ) ) {
		$problems[] = __( 'Hiveclerk is missing its dependencies. Run "composer install" in the plugin directory.', 'hiveclerk' );
	}

	return $problems;
}

/**
 * Show why the plugin could not start, and offer the fix.
 *
 * @param array $problems Requirement failures.
 * @return void
 */
function hiveclerk_show_requirements_notice( $problems ) {
	echo '<div class="notice notice-error"><p><strong>';
	echo esc_html__( 'Hiveclerk could not start.', 'hiveclerk' );
	echo '</strong></p><ul style="list-style:disc;padding-left:20px;">';

	foreach ( $problems as $problem ) {
		echo '<li>' . esc_html( $problem ) . '</li>';
	}

	echo '</ul></div>';
}

/**
 * Start the plugin once the environment is known to be supported.
 *
 * @return void
 */
function hiveclerk_bootstrap() {
	$problems = hiveclerk_unmet_requirements();

	if ( ! empty( $problems ) ) {
		add_action(
			'admin_notices',
			function () use ( $problems ) {
				hiveclerk_show_requirements_notice( $problems );
			}
		);

		return;
	}

	require_once HIVECLERK_DIR . 'vendor/autoload.php';

	\Hiveclerk\Plugin::instance()->boot();
}

add_action( 'plugins_loaded', 'hiveclerk_bootstrap', 5 );

/*
 * Bundled translations, loaded on `init` rather than on `plugins_loaded`.
 *
 * WordPress has auto-loaded translations for .org-hosted plugins since 4.6,
 * so this covers the other case: a customer who was sent a .mo file, or a
 * site running a build from outside the directory. Both put it in
 * `languages/`, which nothing would read without this call.
 *
 * On `init` because WordPress 6.7 started warning about translations loaded
 * before that hook — the locale is not final until then, and a textdomain
 * loaded early gets the wrong one on any site that switches locale per user.
 */
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'hiveclerk', false, dirname( HIVECLERK_BASENAME ) . '/languages' );
	}
);

register_activation_hook(
	__FILE__,
	function () {
		$problems = hiveclerk_unmet_requirements();

		if ( ! empty( $problems ) ) {
			deactivate_plugins( HIVECLERK_BASENAME );
			wp_die(
				esc_html( implode( ' ', $problems ) ),
				esc_html__( 'Hiveclerk could not be activated', 'hiveclerk' ),
				array( 'back_link' => true )
			);
		}

		require_once HIVECLERK_DIR . 'vendor/autoload.php';
		\Hiveclerk\Core\Activation\Activator::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( file_exists( HIVECLERK_DIR . 'vendor/autoload.php' ) ) {
			require_once HIVECLERK_DIR . 'vendor/autoload.php';
			\Hiveclerk\Core\Activation\Deactivator::deactivate();
		}
	}
);
