<?php
/**
 * Capability management.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Capabilities;

/**
 * Adds and removes custom capabilities on activation and uninstall.
 */
final class CapabilityManager {

	/**
	 * Bumped whenever {@see Capabilities::roleMap()} changes.
	 */
	public const VERSION = 2;

	/**
	 * Where the last granted version is remembered.
	 */
	private const OPTION = 'hiveclerk_caps_version';

	/**
	 * Re-grant when the role map has changed since the last grant.
	 *
	 * Capabilities used to be written on activation and never again, which
	 * is correct exactly once. A capability added in a later release then
	 * reached only the sites that happened to deactivate and reactivate —
	 * every upgraded site had the new screen, the new routes and no role
	 * holding the capability that opens them, so the feature answered 403
	 * to the administrator who had just paid for it.
	 *
	 * One integer comparison against an autoloaded option on `admin_init`,
	 * and the write happens on the release that changes the map.
	 *
	 * @return bool Whether anything was granted.
	 */
	public static function syncIfStale(): bool {
		if ( (int) get_option( self::OPTION, 0 ) >= self::VERSION ) {
			return false;
		}

		self::grant();

		update_option( self::OPTION, self::VERSION, true );

		return true;
	}

	/**
	 * Grant capabilities to their default roles.
	 *
	 * @return void
	 */
	public static function grant(): void {
		foreach ( Capabilities::roleMap() as $roleName => $capabilities ) {
			$role = get_role( $roleName );

			if ( null === $role ) {
				continue;
			}

			foreach ( $capabilities as $capability ) {
				$role->add_cap( $capability );
			}
		}
	}

	/**
	 * Remove every capability from every role.
	 *
	 * @return void
	 */
	public static function revoke(): void {
		$roles = wp_roles();

		foreach ( array_keys( $roles->roles ) as $roleName ) {
			$role = get_role( $roleName );

			if ( null === $role ) {
				continue;
			}

			foreach ( Capabilities::all() as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}

	/**
	 * Whether the current user holds a capability.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	public static function currentUserCan( string $capability ): bool {
		return current_user_can( $capability );
	}

	/**
	 * The capability that grants access to the admin app at all.
	 *
	 * Any Hiveclerk capability opens the app; individual screens then
	 * gate themselves.
	 *
	 * @return string
	 */
	public static function menuCapability(): string {
		return Capabilities::VIEW_CONVERSATIONS;
	}
}
