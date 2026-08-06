<?php
/**
 * Uninstall routine.
 *
 * Only removes data when the site owner explicitly opted in. Deleting a
 * customer's conversation history because they deactivated a plugin to
 * debug something else would be indefensible.
 *
 * Nothing here keeps its own list of what to remove. Tables come from
 * `Schema::all()` and options from `Footprint::options()`, because the
 * hand-copied lists this file used to hold were wrong in five places by
 * Sprint 10 — including the row holding the customer's encrypted provider
 * keys — and no test could have caught it while the truth lived in two
 * places.
 *
 * @package Hiveclerk
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$hiveclerk_settings = get_option( 'hiveclerk_settings', array() );
$hiveclerk_purge    = is_array( $hiveclerk_settings )
	&& ! empty( $hiveclerk_settings['privacy']['delete_on_uninstall'] );

if ( ! $hiveclerk_purge ) {
	return;
}

/*
 * Guarded rather than required outright. This runs inside WordPress's
 * uninstall flow, where a fatal leaves the plugin half-removed and the
 * screen showing an error the site owner cannot act on. A missing or
 * broken vendor directory — an interrupted update, a partial upload, a
 * deploy that excluded it — is exactly when that happens, and leaving the
 * data in place is the recoverable outcome: the tables can still be
 * dropped by reinstalling and uninstalling again, whereas a fatal here
 * strands them with no route back.
 */
if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

global $wpdb;

foreach ( \Hiveclerk\Database\Schema::all() as $hiveclerk_table ) {
	$hiveclerk_name = $wpdb->prefix . $hiveclerk_table;

	/*
	 * Table identifiers cannot be bound as placeholders. The name is built
	 * from $wpdb->prefix plus a value from Schema's hard-coded allowlist,
	 * so no caller-supplied input can reach this statement.
	 */
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS `{$hiveclerk_name}`" );
}

foreach ( \Hiveclerk\Core\Activation\Footprint::options() as $hiveclerk_option ) {
	delete_option( $hiveclerk_option );
}

/*
 * Transients are removed by pattern because their names carry ids and
 * provider slugs. On a site with a persistent object cache these rows do
 * not exist and the statement matches nothing; on one without, they are
 * the largest thing the plugin leaves behind — the model catalogue alone
 * measured 113 KB.
 *
 * `$wpdb->esc_like()` guards the underscore, which is a LIKE wildcard: an
 * unescaped `hiveclerk_%` also matches `hiveclerkXfoo`, and a DELETE that
 * matches more than it was meant to is the worst possible bug in an
 * uninstall routine.
 */
foreach ( \Hiveclerk\Core\Activation\Footprint::transientPrefixes() as $hiveclerk_prefix ) {
	foreach ( array( '_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_' ) as $hiveclerk_kind ) {
		$hiveclerk_like = $wpdb->esc_like( $hiveclerk_kind . $hiveclerk_prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$hiveclerk_like
			)
		);
	}
}

\Hiveclerk\Core\Capabilities\CapabilityManager::revoke();

\Hiveclerk\Core\Activation\Footprint::unscheduleAll();

/*
 * Our own cache groups, never the whole cache. `wp_cache_flush()` would
 * empty every other plugin's entries on the site as a side effect of
 * uninstalling this one, which is a performance incident somebody else
 * gets paged for. Group flushing needs both a WordPress new enough to
 * offer it and a backend that implements it; where it is unavailable the
 * entries expire on their own, which is why the group list is allowed to
 * be best-effort and the option list is not.
 */
if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
	foreach ( \Hiveclerk\Core\Activation\Footprint::cacheGroups() as $hiveclerk_group ) {
		wp_cache_flush_group( $hiveclerk_group );
	}
}
