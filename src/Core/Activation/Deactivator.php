<?php
/**
 * Deactivation routine.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Activation;

/**
 * Runs when the plugin is deactivated.
 *
 * Deactivation is not uninstallation. Nothing belonging to the customer is
 * removed here; a deactivate/reactivate cycle must be lossless.
 */
final class Deactivator {

	/**
	 * Deactivate.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		delete_transient( 'hiveclerk_activation_redirect' );

		wp_clear_scheduled_hook( 'hiveclerk_daily_maintenance' );
		wp_clear_scheduled_hook( 'hiveclerk_hourly_rollup' );
	}
}
