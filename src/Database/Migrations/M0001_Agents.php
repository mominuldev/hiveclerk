<?php
/**
 * Agent tables.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Migrations;

use Hiveclerk\Database\Migration;
use Hiveclerk\Database\Schema;

/**
 * Creates the clerk tables.
 */
final class M0001_Agents extends Migration {

	public function version(): int {
		return 1;
	}

	public function description(): string {
		return 'Create agent tables';
	}

	public function up(): void {
		$agents  = Schema::table( Schema::AGENTS );
		$sources = Schema::table( Schema::AGENT_SOURCES );
		$charset = $this->charset();

		/*
		 * personality, guardrails, display_rules and friends are JSON because
		 * they are read as a whole and never queried by sub-field. Normalising
		 * them would mean a migration for every new guardrail. Anything that
		 * IS queried — status, token_budget — stays a real column.
		 */
		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$agents}` (
				id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				uuid              CHAR(36)        NOT NULL,
				name              VARCHAR(191)    NOT NULL,
				slug              VARCHAR(191)    NOT NULL,
				role_preset       VARCHAR(32)     NOT NULL DEFAULT 'support',
				status            VARCHAR(20)     NOT NULL DEFAULT 'draft',
				avatar_url        VARCHAR(500)        NULL,
				greeting          TEXT                NULL,
				fallback_message  TEXT                NULL,
				instructions      LONGTEXT            NULL,
				personality       JSON                NULL,
				guardrails        JSON                NULL,
				model_config      JSON                NULL,
				retrieval_config  JSON                NULL,
				display_rules     JSON                NULL,
				widget_config     JSON                NULL,
				lead_config       JSON                NULL,
				token_budget      INT UNSIGNED        NULL,
				tokens_used_month INT UNSIGNED    NOT NULL DEFAULT 0,
				budget_reset_at   DATETIME            NULL,
				created_by        BIGINT UNSIGNED     NULL,
				created_at        DATETIME        NOT NULL,
				updated_at        DATETIME        NOT NULL,
				deleted_at        DATETIME            NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_uuid (uuid),
				UNIQUE KEY uq_slug (slug),
				KEY idx_status (status, deleted_at),
				KEY idx_created (created_at)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);

		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$sources}` (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				agent_id   BIGINT UNSIGNED NOT NULL,
				source_id  BIGINT UNSIGNED NOT NULL,
				priority   SMALLINT        NOT NULL DEFAULT 0,
				created_at DATETIME        NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_agent_source (agent_id, source_id),
				KEY idx_source (source_id)
			) ENGINE=InnoDB {$charset}"
		);
	}

	public function down(): void {
		$this->drop( Schema::AGENT_SOURCES );
		$this->drop( Schema::AGENTS );
	}
}
