<?php
/**
 * Everything this plugin leaves behind on a site.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Activation;

use Hiveclerk\Core\Queue\QueueInterface;

/**
 * The complete list of what an install writes outside its own tables.
 *
 * This class exists because the alternative — uninstall.php keeping its own
 * copy of the list — was wrong in five places by Sprint 10 and nothing
 * noticed. A hand-maintained duplicate of a list that grows every sprint is
 * a list that is correct on the day it is written and silently wrong from
 * the next one, and the failure is invisible: uninstalling leaves rows
 * behind, and nobody uninstalls a plugin and then goes looking in
 * `wp_options` to check.
 *
 * Tables are not listed here. `Schema::all()` is already the allowlist that
 * makes table names checkable, and a second copy would reintroduce exactly
 * the problem this class was written to remove.
 *
 * Scheduled work is not listed here either, for a stronger reason: it is
 * swept by prefix. Every hook this product schedules is namespaced
 * `hiveclerk/`, so asking the cron array what is actually scheduled cannot
 * drift from what really is, whereas a list of hook names can and did.
 */
final class Footprint {

	/**
	 * The prefix every hook this plugin schedules carries.
	 */
	public const HOOK_PREFIX = 'hiveclerk/';

	/**
	 * Every option this plugin writes.
	 *
	 * Each is annotated with what it holds, because the decision an
	 * uninstall makes about a row depends entirely on that: a cache may
	 * go at any time, a salt may only go with the ciphertext it derives,
	 * and the customer's encrypted provider keys are the one row here
	 * that matters most and was the one missing.
	 *
	 * @return array<int, string>
	 */
	public static function options(): array {
		return array(
			// Configuration and install state.
			'hiveclerk_settings',
			'hiveclerk_version',
			'hiveclerk_db_version',
			'hiveclerk_installed_at',
			'hiveclerk_needs_migration',
			'hiveclerk_onboarding',

			/*
			 * Secrets. `hiveclerk_provider_keys` holds the customer's
			 * model API keys, encrypted with a key derived from the
			 * WordPress salts plus `hiveclerk_encryption_salt`. Deleting
			 * the salt and keeping the ciphertext — which is what the
			 * previous list did — leaves an undecryptable blob in the
			 * options table of a site that believes it removed the
			 * plugin entirely. The two must go together or not at all.
			 */
			'hiveclerk_provider_keys',
			'hiveclerk_encryption_salt',
			'hiveclerk_session_salt',

			// Licensing.
			'hiveclerk_licence_key',
			'hiveclerk_licence_state',

			// Cache generation counter.
			'hiveclerk_matrix_generation',

			/*
			 * When each background job last actually ran. Operational rather
			 * than secret, but it is ours and an uninstall that left it
			 * behind would hand a reinstalled plugin a set of timestamps
			 * describing runs that happened before the tables existed.
			 */
			'hiveclerk_job_runs',

			/*
			 * Written by Activator before Sprint 9 and read by nothing
			 * since: OnboardingState settled on `hiveclerk_onboarding`.
			 * Kept in the list, and only in the list, so the row is
			 * cleaned off the installs that already carry it.
			 */
			'hiveclerk_onboarding_state',
		);
	}

	/**
	 * Prefixes of every transient this plugin sets.
	 *
	 * Transients matter to an uninstall in a way they do not to a
	 * deactivation. On a site with no persistent object cache a transient
	 * is two rows in the options table, and the model catalogue alone
	 * measured 113 KB on the development site. They expire eventually,
	 * but "eventually" is not what a customer who ticked *delete
	 * everything* asked for.
	 *
	 * @return array<int, string>
	 */
	public static function transientPrefixes(): array {
		return array(
			'hiveclerk_',
			'hvc_',
		);
	}


	/**
	 * Object cache groups this plugin writes into.
	 *
	 * Unlike the option list, being wrong here is bounded: every entry in
	 * both groups carries a TTL and is keyed by a generation counter or a
	 * conversation id, so anything missed expires on its own and can never
	 * be read back by a later install. That is why these names are
	 * repeated from the two module classes that own them rather than
	 * making a private constant public and pointing `Core` at `Modules`.
	 *
	 * @return array<int, string>
	 */
	public static function cacheGroups(): array {
		return array(
			'hiveclerk_matrix',
			'hiveclerk_chat',
		);
	}

	/**
	 * Unschedule everything this plugin has queued.
	 *
	 * Swept by prefix rather than by name. The list this replaced named
	 * two hooks — `hiveclerk_daily_maintenance` and
	 * `hiveclerk_hourly_rollup` — that appear nowhere else in the
	 * codebase and have never been scheduled, while the three that are
	 * scheduled were left running: a deactivated plugin kept a five-minute
	 * recurring event pointed at a hook with no listener.
	 *
	 * @return void
	 */
	public static function unscheduleAll(): void {
		foreach ( array_keys( self::scheduledHooks() ) as $hook ) {
			wp_unschedule_hook( $hook );
		}

		/*
		 * Action Scheduler is not bundled, so every call to it is guarded.
		 * When it is present it holds our work in its own tables and the
		 * cron sweep above sees none of it.
		 */
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), QueueInterface::GROUP );
		}
	}

	/**
	 * This plugin's scheduled hooks, mapped to when each next runs.
	 *
	 * Read out of the cron array rather than through
	 * `wp_next_scheduled()`, which is the obvious call and the wrong one.
	 * That function looks an event up by a hash of its arguments, and
	 * every recurring job here is registered through `CronQueue`, which
	 * wraps its argument array — so the stored signature is
	 * `md5( serialize( array( array() ) ) )` and the signature
	 * `wp_next_scheduled( $hook )` computes is `md5( serialize( array() ) )`.
	 * It returns false for all three of our jobs on a site where all three
	 * are scheduled and running. The health screen built on that reported
	 * "never scheduled" for a healthy install, which is the exact false
	 * alarm it exists to avoid.
	 *
	 * Timestamps are UTC Unix time, and the earliest wins where a hook
	 * appears more than once.
	 *
	 * @return array<string, int>
	 */
	public static function scheduledHooks(): array {
		$cron = _get_cron_array();

		if ( ! is_array( $cron ) ) {
			return array();
		}

		$hooks = array();

		foreach ( $cron as $timestamp => $hooksAtTime ) {
			if ( ! is_array( $hooksAtTime ) || ! is_int( $timestamp ) ) {
				continue;
			}

			foreach ( array_keys( $hooksAtTime ) as $hook ) {
				if ( ! is_string( $hook ) || ! str_starts_with( $hook, self::HOOK_PREFIX ) ) {
					continue;
				}

				$hooks[ $hook ] = min( $hooks[ $hook ] ?? $timestamp, $timestamp );
			}
		}

		return $hooks;
	}
}
