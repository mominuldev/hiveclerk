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
