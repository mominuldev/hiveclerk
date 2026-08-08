<?php
/**
 * Workflow automation tables.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Migrations;

use Hiveclerk\Database\Migration;
use Hiveclerk\Database\Schema;

/**
 * Creates the workflow, run and run-log tables (FR-WFL-01…07).
 */
final class M0013_Workflows extends Migration {

	public function version(): int {
		return 13;
	}

	public function description(): string {
		return 'Create workflow automation tables';
	}

	public function up(): void {
		$workflows = Schema::table( Schema::WORKFLOWS );
		$runs      = Schema::table( Schema::WORKFLOW_RUNS );
		$log       = Schema::table( Schema::WORKFLOW_RUN_LOG );
		$charset   = $this->charset();

		// next_run_at is a column rather than a key in trigger_config
		// because the scheduled sweep queries on it every five minutes.
		// A JSON extraction there would be a full scan of a table nobody
		// thinks of as hot.
		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$workflows}` (
				id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				uuid           CHAR(36)        NOT NULL,
				name           VARCHAR(191)    NOT NULL,
				status         VARCHAR(20)     NOT NULL DEFAULT 'draft',
				trigger_event  VARCHAR(50)     NOT NULL,
				trigger_config JSON                NULL,
				graph          JSON                NULL,
				runs_once      TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
				next_run_at    DATETIME            NULL,
				run_count      INT UNSIGNED    NOT NULL DEFAULT 0,
				last_run_at    DATETIME            NULL,
				created_at     DATETIME        NOT NULL,
				updated_at     DATETIME        NOT NULL,
				deleted_at     DATETIME            NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_uuid (uuid),
				KEY idx_live (status, trigger_event, deleted_at),
				KEY idx_schedule (status, next_run_at)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);

		/*
		 * uq_open_subject is the re-entry guard, enforced by the database
		 * rather than by a read-then-write in the router. Two lead events
		 * arriving in the same second from two requests would otherwise
		 * both find no open run and both open one, and the visible symptom
		 * is a lead receiving everything twice.
		 *
		 * It covers open runs only: `open_key` holds the subject id while
		 * the run is live and NULL once it finishes, and MySQL does not
		 * apply a unique constraint to NULLs. That is what lets a subject
		 * run again later without the index having to know about status.
		 */
		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$runs}` (
				id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				workflow_id  BIGINT UNSIGNED NOT NULL,
				subject_type VARCHAR(20)     NOT NULL DEFAULT 'lead',
				subject_id   BIGINT UNSIGNED     NULL,
				open_key     BIGINT UNSIGNED     NULL,
				status       VARCHAR(20)     NOT NULL DEFAULT 'pending',
				current_node VARCHAR(64)         NULL,
				resume_at    DATETIME            NULL,
				attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				steps        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				context      JSON                NULL,
				error        TEXT                NULL,
				started_at   DATETIME        NOT NULL,
				updated_at   DATETIME        NOT NULL,
				finished_at  DATETIME            NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_open_subject (workflow_id, open_key),
				KEY idx_due (status, resume_at),
				KEY idx_workflow (workflow_id, id),
				KEY idx_subject (workflow_id, subject_id),
				KEY idx_prune (status, finished_at)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);

		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$log}` (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				run_id     BIGINT UNSIGNED NOT NULL,
				node_id    VARCHAR(64)     NOT NULL,
				node_type  VARCHAR(20)     NOT NULL,
				outcome    VARCHAR(20)     NOT NULL,
				detail     TEXT                NULL,
				created_at DATETIME        NOT NULL,
				PRIMARY KEY  (id),
				KEY idx_run (run_id, id)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);
	}

	public function down(): void {
		$this->drop( Schema::WORKFLOW_RUN_LOG );
		$this->drop( Schema::WORKFLOW_RUNS );
		$this->drop( Schema::WORKFLOWS );
	}
}
