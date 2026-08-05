<?php
/**
 * Activation routine.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Activation;

use Hiveclerk\Core\Capabilities\CapabilityManager;

/**
 * Runs once when the plugin is activated.
 */
final class Activator {

	/**
	 * Activate.
	 *
	 * @return void
	 */
	public static function activate(): void {
		CapabilityManager::grant();

		if ( false === get_option( 'hiveclerk_installed_at' ) ) {
			add_option( 'hiveclerk_installed_at', gmdate( 'Y-m-d H:i:s' ), '', false );
			add_option( 'hiveclerk_onboarding_state', array( 'step' => 0 ), '', false );
		}

		update_option( 'hiveclerk_version', HIVECLERK_VERSION, false );

		// Migrations run on admin_init rather than here: activation has a
		// short execution budget and a failure would leave the plugin
		// half-installed with no way to report why.
		update_option( 'hiveclerk_needs_migration', true, false );

		set_transient( 'hiveclerk_activation_redirect', true, 30 );
	}
}
