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

		// Stamped here as well as by the admin_init sync, so a fresh
		// install does not spend its first admin request re-granting what
		// the line above just granted.
		update_option( 'hiveclerk_caps_version', CapabilityManager::VERSION, true );

		/*
		 * No onboarding row is seeded. This used to write
		 * `hiveclerk_onboarding_state`, which Sprint 9's OnboardingState
		 * did not adopt — it owns `hiveclerk_onboarding` and treats a
		 * missing option as "not started", which is the correct reading
		 * of a site that has just installed the plugin. The seeded row was
		 * therefore written on every activation and read by nothing.
		 */
		if ( false === get_option( 'hiveclerk_installed_at' ) ) {
			add_option( 'hiveclerk_installed_at', gmdate( 'Y-m-d H:i:s' ), '', false );
		}

		update_option( 'hiveclerk_version', HIVECLERK_VERSION, false );

		// Migrations run on admin_init rather than here: activation has a
		// short execution budget and a failure would leave the plugin
		// half-installed with no way to report why.
		update_option( 'hiveclerk_needs_migration', true, false );

		set_transient( 'hiveclerk_activation_redirect', true, 30 );
	}
}
