<?php
/**
 * Conversation tables.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Migrations;

use Hiveclerk\Database\Migration;
use Hiveclerk\Database\Schema;

/**
 * Creates the visitor, session, conversation and message tables.
 */
final class M0003_Conversations extends Migration {

	public function version(): int {
		return 3;
	}

	public function description(): string {
		return 'Create conversation tables';
	}

	public function up(): void {
		$visitors      = Schema::table( Schema::VISITORS );
		$sessions      = Schema::table( Schema::SESSIONS );
		$conversations = Schema::table( Schema::CONVERSATIONS );
		$messages      = Schema::table( Schema::MESSAGES );
		$citations     = Schema::table( Schema::MESSAGE_CITATIONS );
		$charset       = $this->charset();

		// IPs are stored hashed, never raw. There is no product feature that
		// needs the original address, and holding one creates a GDPR
		// obligation for no benefit.
		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$visitors}` (
				id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				uuid            CHAR(36)        NOT NULL,
				wp_user_id      BIGINT UNSIGNED     NULL,
				lead_id         BIGINT UNSIGNED     NULL,
				fingerprint     CHAR(64)            NULL,
				ip_hash         CHAR(64)            NULL,
				user_agent      VARCHAR(500)        NULL,
				country         CHAR(2)             NULL,
				language        VARCHAR(10)         NULL,
				first_seen_at   DATETIME        NOT NULL,
				last_seen_at    DATETIME        NOT NULL,
				page_views      INT UNSIGNED    NOT NULL DEFAULT 0,
				session_count   INT UNSIGNED    NOT NULL DEFAULT 1,
				metadata        JSON                NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_uuid (uuid),
				KEY idx_lead (lead_id),
				KEY idx_wp_user (wp_user_id),
				KEY idx_last_seen (last_seen_at)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);

		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$sessions}` (
				id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				uuid            CHAR(36)        NOT NULL,
				visitor_id      BIGINT UNSIGNED     NULL,
				conversation_id BIGINT UNSIGNED     NULL,
				token_hash      CHAR(64)        NOT NULL,
				transport       VARCHAR(10)     NOT NULL DEFAULT 'sse',
				ip_hash         CHAR(64)            NULL,
				expires_at      DATETIME        NOT NULL,
				created_at      DATETIME        NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_uuid (uuid),
				UNIQUE KEY uq_token (token_hash),
				KEY idx_expires (expires_at)
			) ENGINE=InnoDB {$charset}"
		);

		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$conversations}` (
				id                BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
				uuid              CHAR(36)          NOT NULL,
				agent_id          BIGINT UNSIGNED   NOT NULL,
				visitor_id        BIGINT UNSIGNED       NULL,
				lead_id           BIGINT UNSIGNED       NULL,
				status            VARCHAR(20)       NOT NULL DEFAULT 'active',
				channel           VARCHAR(20)       NOT NULL DEFAULT 'widget',
				language          VARCHAR(10)           NULL,
				page_url          VARCHAR(500)          NULL,
				page_title        VARCHAR(500)          NULL,
				message_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				summary           TEXT                  NULL,
				sentiment         VARCHAR(20)           NULL,
				sentiment_score   DECIMAL(4,3)          NULL,
				resolved_by_ai    TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				handoff_user_id   BIGINT UNSIGNED       NULL,
				handoff_at        DATETIME              NULL,
				rating            TINYINT               NULL,
				tags              JSON                  NULL,
				total_tokens_in   INT UNSIGNED      NOT NULL DEFAULT 0,
				total_tokens_out  INT UNSIGNED      NOT NULL DEFAULT 0,
				total_cost        DECIMAL(12,6)     NOT NULL DEFAULT 0,
				started_at        DATETIME          NOT NULL,
				last_message_at   DATETIME              NULL,
				ended_at          DATETIME              NULL,
				purge_after       DATETIME              NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_uuid (uuid),
				KEY idx_agent_started (agent_id, started_at),
				KEY idx_status (status, started_at),
				KEY idx_lead (lead_id),
				KEY idx_visitor (visitor_id),
				KEY idx_purge (purge_after)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);

		// idx_conversation is (conversation_id, created_at) so the transcript
		// query's sort order matches index order and never needs a filesort.
		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$messages}` (
				id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				uuid             CHAR(36)        NOT NULL,
				conversation_id  BIGINT UNSIGNED NOT NULL,
				role             VARCHAR(16)     NOT NULL,
				content          LONGTEXT        NOT NULL,
				content_html     LONGTEXT            NULL,
				wp_user_id       BIGINT UNSIGNED     NULL,
				provider         VARCHAR(32)         NULL,
				model            VARCHAR(64)         NULL,
				tokens_in        INT UNSIGNED    NOT NULL DEFAULT 0,
				tokens_out       INT UNSIGNED    NOT NULL DEFAULT 0,
				cost             DECIMAL(12,6)   NOT NULL DEFAULT 0,
				latency_ms       INT UNSIGNED        NULL,
				retrieval_score  DECIMAL(5,4)        NULL,
				is_grounded      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				guardrail_flags  JSON                NULL,
				rating           TINYINT             NULL,
				rating_comment   VARCHAR(500)        NULL,
				created_at       DATETIME        NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_uuid (uuid),
				KEY idx_conversation (conversation_id, created_at),
				KEY idx_created (created_at),
				KEY idx_rating (rating)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);

		// snapshot preserves the citation after the source is re-indexed or
		// deleted, so a historical transcript stays auditable.
		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$citations}` (
				id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
				message_id  BIGINT UNSIGNED  NOT NULL,
				chunk_id    BIGINT UNSIGNED      NULL,
				document_id BIGINT UNSIGNED      NULL,
				score       DECIMAL(5,4)     NOT NULL DEFAULT 0,
				rank_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
				snapshot    JSON                 NULL,
				PRIMARY KEY  (id),
				KEY idx_message (message_id, rank_order),
				KEY idx_chunk (chunk_id)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);
	}

	public function down(): void {
		$this->drop( Schema::MESSAGE_CITATIONS );
		$this->drop( Schema::MESSAGES );
		$this->drop( Schema::CONVERSATIONS );
		$this->drop( Schema::SESSIONS );
		$this->drop( Schema::VISITORS );
	}
}
