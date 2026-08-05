<?php
/**
 * Uninstall routine.
 *
 * Only removes data when the site owner explicitly opted in. Deleting a
 * customer's conversation history because they deactivated a plugin to
 * debug something else would be indefensible.
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

require_once __DIR__ . '/vendor/autoload.php';

global $wpdb;

$hiveclerk_tables = array(
	'hvc_agents',
	'hvc_agent_sources',
	'hvc_knowledge_sources',
	'hvc_documents',
	'hvc_chunks',
	'hvc_embeddings',
	'hvc_visitors',
	'hvc_sessions',
	'hvc_conversations',
	'hvc_messages',
	'hvc_message_citations',
	'hvc_leads',
	'hvc_lead_scores',
	'hvc_lead_stages',
	'hvc_activities',
	'hvc_email_sequences',
	'hvc_sequence_steps',
	'hvc_sequence_enrollments',
	'hvc_email_log',
	'hvc_suppressions',
	'hvc_integrations',
	'hvc_integration_log',
	'hvc_usage_events',
	'hvc_analytics_daily',
	'hvc_unanswered',
	'hvc_audit_log',
	'hvc_rate_limits',
);

foreach ( $hiveclerk_tables as $hiveclerk_table ) {
	$hiveclerk_name = $wpdb->prefix . $hiveclerk_table;

	/*
	 * Table identifiers cannot be bound as placeholders. The name is built
	 * from $wpdb->prefix plus a value from the hard-coded allowlist above,
	 * so no caller-supplied input can reach this statement.
	 */
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS `{$hiveclerk_name}`" );
}

$hiveclerk_options = array(
	'hiveclerk_settings',
	'hiveclerk_version',
	'hiveclerk_db_version',
	'hiveclerk_installed_at',
	'hiveclerk_onboarding_state',
	'hiveclerk_needs_migration',
	'hiveclerk_encryption_salt',
	'hiveclerk_licence',
);

foreach ( $hiveclerk_options as $hiveclerk_option ) {
	delete_option( $hiveclerk_option );
}

\Hiveclerk\Core\Capabilities\CapabilityManager::revoke();

wp_clear_scheduled_hook( 'hiveclerk_daily_maintenance' );
wp_clear_scheduled_hook( 'hiveclerk_hourly_rollup' );
