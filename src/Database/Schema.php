<?php
/**
 * Table name registry.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database;

/**
 * The single source of truth for table names.
 *
 * Nothing else in the codebase concatenates a table name. Every identifier
 * that reaches SQL comes from a constant here, which is what lets the
 * "no user input in an identifier position" guarantee be checked by reading
 * one file.
 */
final class Schema {

	public const AGENTS               = 'hvc_agents';
	public const AGENT_SOURCES        = 'hvc_agent_sources';
	public const KNOWLEDGE_SOURCES    = 'hvc_knowledge_sources';
	public const DOCUMENTS            = 'hvc_documents';
	public const CHUNKS               = 'hvc_chunks';
	public const EMBEDDINGS           = 'hvc_embeddings';
	public const VISITORS             = 'hvc_visitors';
	public const SESSIONS             = 'hvc_sessions';
	public const CONVERSATIONS        = 'hvc_conversations';
	public const MESSAGES             = 'hvc_messages';
	public const MESSAGE_CITATIONS    = 'hvc_message_citations';
	public const LEADS                = 'hvc_leads';
	public const LEAD_SCORES          = 'hvc_lead_scores';
	public const LEAD_STAGES          = 'hvc_lead_stages';
	public const ACTIVITIES           = 'hvc_activities';
	public const EMAIL_SEQUENCES      = 'hvc_email_sequences';
	public const SEQUENCE_STEPS       = 'hvc_sequence_steps';
	public const SEQUENCE_ENROLLMENTS = 'hvc_sequence_enrollments';
	public const EMAIL_LOG            = 'hvc_email_log';
	public const SUPPRESSIONS         = 'hvc_suppressions';
	public const INTEGRATIONS         = 'hvc_integrations';
	public const INTEGRATION_LOG      = 'hvc_integration_log';
	public const USAGE_EVENTS         = 'hvc_usage_events';
	public const ANALYTICS_DAILY      = 'hvc_analytics_daily';
	public const UNANSWERED           = 'hvc_unanswered';
	public const AUDIT_LOG            = 'hvc_audit_log';
	public const RATE_LIMITS          = 'hvc_rate_limits';

	/**
	 * Every table this plugin owns.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::AGENTS,
			self::AGENT_SOURCES,
			self::KNOWLEDGE_SOURCES,
			self::DOCUMENTS,
			self::CHUNKS,
			self::EMBEDDINGS,
			self::VISITORS,
			self::SESSIONS,
			self::CONVERSATIONS,
			self::MESSAGES,
			self::MESSAGE_CITATIONS,
			self::LEADS,
			self::LEAD_SCORES,
			self::LEAD_STAGES,
			self::ACTIVITIES,
			self::EMAIL_SEQUENCES,
			self::SEQUENCE_STEPS,
			self::SEQUENCE_ENROLLMENTS,
			self::EMAIL_LOG,
			self::SUPPRESSIONS,
			self::INTEGRATIONS,
			self::INTEGRATION_LOG,
			self::USAGE_EVENTS,
			self::ANALYTICS_DAILY,
			self::UNANSWERED,
			self::AUDIT_LOG,
			self::RATE_LIMITS,
		);
	}

	/**
	 * Fully prefixed table name.
	 *
	 * @param string $table One of this class's constants.
	 * @return string
	 *
	 * @throws \InvalidArgumentException When the name is not a known table.
	 */
	public static function table( string $table ): string {
		if ( ! in_array( $table, self::all(), true ) ) {
			throw new \InvalidArgumentException(
				sprintf( '"%s" is not a Hiveclerk table.', $table )
			);
		}

		global $wpdb;

		return $wpdb->prefix . $table;
	}
}
