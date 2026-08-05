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

		/*
		 * Swept by prefix. This used to name two hooks that appear nowhere
		 * else in the codebase and have never been scheduled, while the
		 * three that are — the sequence tick every five minutes, the
		 * hourly rollup and the conversation purge — survived deactivation
		 * and kept firing at a hook with no listener for as long as the
		 * site lived. Nothing errors in that state, which is why it lasted:
		 * WP-Cron fires the action, no callback is registered, and the
		 * event reschedules itself.
		 */
		Footprint::unscheduleAll();
	}
}
