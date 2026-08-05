<?php
/**
 * Lead tables.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Migrations;

use Hiveclerk\Database\Migration;
use Hiveclerk\Database\Schema;

/**
 * Creates the lead, scoring, pipeline and activity tables.
 */
final class M0004_Leads extends Migration {

	public function version(): int {
		return 4;
	}

	public function description(): string {
		return 'Create lead tables';
	}

	public function up(): void {
		$stages     = Schema::table( Schema::LEAD_STAGES );
		$leads      = Schema::table( Schema::LEADS );
		$scores     = Schema::table( Schema::LEAD_SCORES );
		$activities = Schema::table( Schema::ACTIVITIES );
		$charset    = $this->charset();

		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$stages}` (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name       VARCHAR(191)    NOT NULL,
				slug       VARCHAR(191)    NOT NULL,
				color      VARCHAR(20)         NULL,
				position   SMALLINT        NOT NULL DEFAULT 0,
				is_won     TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				is_lost    TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME        NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_slug (slug),
				KEY idx_position (position)
			) ENGINE=InnoDB {$charset}"
		);

		// email_hash carries the unique index so duplicates are caught without
		// putting a plaintext address in an index.
		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$leads}` (
				id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				uuid            CHAR(36)        NOT NULL,
				email           VARCHAR(191)        NULL,
				email_hash      CHAR(64)            NULL,
				first_name      VARCHAR(100)        NULL,
				last_name       VARCHAR(100)        NULL,
				phone           VARCHAR(50)         NULL,
				company         VARCHAR(191)        NULL,
				job_title       VARCHAR(191)        NULL,
				website         VARCHAR(255)        NULL,
				wp_user_id      BIGINT UNSIGNED     NULL,
				stage_id        BIGINT UNSIGNED     NULL,
				score           SMALLINT        NOT NULL DEFAULT 0,
				score_band      VARCHAR(20)     NOT NULL DEFAULT 'cold',
				status          VARCHAR(20)     NOT NULL DEFAULT 'new',
				source          VARCHAR(50)         NULL,
				custom_fields   JSON                NULL,
				consent         JSON                NULL,
				owner_user_id   BIGINT UNSIGNED     NULL,
				first_seen_at   DATETIME        NOT NULL,
				last_active_at  DATETIME            NULL,
				converted_at    DATETIME            NULL,
				created_at      DATETIME        NOT NULL,
				updated_at      DATETIME        NOT NULL,
				deleted_at      DATETIME            NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_uuid (uuid),
				UNIQUE KEY uq_email_hash (email_hash),
				KEY idx_score (score_band, score),
				KEY idx_stage (stage_id),
				KEY idx_status (status, created_at),
				KEY idx_owner (owner_user_id)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);

		/*
		 * Append-only. A score is never updated in place; each adjustment is a
		 * new row carrying its own rationale, and leads.score is the
		 * materialised running total. That is what makes the breakdown
		 * auditable rather than a number nobody trusts.
		 */
		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$scores}` (
				id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				lead_id         BIGINT UNSIGNED NOT NULL,
				conversation_id BIGINT UNSIGNED     NULL,
				rule_id         VARCHAR(64)         NULL,
				rule_label      VARCHAR(191)        NULL,
				source          VARCHAR(20)     NOT NULL DEFAULT 'rule',
				points          SMALLINT        NOT NULL DEFAULT 0,
				score_after     SMALLINT        NOT NULL DEFAULT 0,
				rationale       TEXT                NULL,
				created_at      DATETIME        NOT NULL,
				PRIMARY KEY  (id),
				KEY idx_lead (lead_id, created_at),
				KEY idx_conversation (conversation_id)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);

		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$activities}` (
				id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				lead_id      BIGINT UNSIGNED     NULL,
				visitor_id   BIGINT UNSIGNED     NULL,
				type         VARCHAR(50)     NOT NULL,
				subject_type VARCHAR(50)         NULL,
				subject_id   BIGINT UNSIGNED     NULL,
				wp_user_id   BIGINT UNSIGNED     NULL,
				title        VARCHAR(255)    NOT NULL,
				body         TEXT                NULL,
				metadata     JSON                NULL,
				created_at   DATETIME        NOT NULL,
				PRIMARY KEY  (id),
				KEY idx_lead (lead_id, created_at),
				KEY idx_visitor (visitor_id, created_at),
				KEY idx_type (type, created_at),
				KEY idx_subject (subject_type, subject_id)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);
	}

	public function down(): void {
		$this->drop( Schema::ACTIVITIES );
		$this->drop( Schema::LEAD_SCORES );
		$this->drop( Schema::LEADS );
		$this->drop( Schema::LEAD_STAGES );
	}
}
