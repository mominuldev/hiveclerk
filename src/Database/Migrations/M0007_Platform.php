<?php
/**
 * Platform tables.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Migrations;

use Hiveclerk\Database\Migration;
use Hiveclerk\Database\Schema;

/**
 * Creates usage, analytics, audit and rate-limit tables.
 */
final class M0007_Platform extends Migration {

	public function version(): int {
		return 7;
	}

	public function description(): string {
		return 'Create platform tables';
	}

	public function up(): void {
		$usage      = Schema::table( Schema::USAGE_EVENTS );
		$analytics  = Schema::table( Schema::ANALYTICS_DAILY );
		$unanswered = Schema::table( Schema::UNANSWERED );
		$audit      = Schema::table( Schema::AUDIT_LOG );
		$limits     = Schema::table( Schema::RATE_LIMITS );
		$charset    = $this->charset();

		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$usage}` (
				id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				agent_id        BIGINT UNSIGNED     NULL,
				conversation_id BIGINT UNSIGNED     NULL,
				kind            VARCHAR(20)     NOT NULL,
				provider        VARCHAR(32)     NOT NULL,
				model           VARCHAR(64)     NOT NULL,
				tokens_in       INT UNSIGNED    NOT NULL DEFAULT 0,
				tokens_out      INT UNSIGNED    NOT NULL DEFAULT 0,
				cost            DECIMAL(12,6)   NOT NULL DEFAULT 0,
				latency_ms      INT UNSIGNED        NULL,
				occurred_at     DATETIME        NOT NULL,
				PRIMARY KEY  (id),
				KEY idx_agent_time (agent_id, occurred_at),
				KEY idx_kind_time (kind, occurred_at),
				KEY idx_occurred (occurred_at)
			) ENGINE=InnoDB {$charset}"
		);

		/*
		 * Pre-aggregated because the dashboard must never scan messages. A
		 * site with 50,000 conversations would otherwise make the first
		 * screen of the product unusable.
		 */
		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$analytics}` (
				id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				date             DATE            NOT NULL,
				agent_id         BIGINT UNSIGNED     NULL,
				conversations    INT UNSIGNED    NOT NULL DEFAULT 0,
				messages         INT UNSIGNED    NOT NULL DEFAULT 0,
				unique_visitors  INT UNSIGNED    NOT NULL DEFAULT 0,
				leads_captured   INT UNSIGNED    NOT NULL DEFAULT 0,
				leads_qualified  INT UNSIGNED    NOT NULL DEFAULT 0,
				handoffs         INT UNSIGNED    NOT NULL DEFAULT 0,
				resolved_by_ai   INT UNSIGNED    NOT NULL DEFAULT 0,
				positive_ratings INT UNSIGNED    NOT NULL DEFAULT 0,
				negative_ratings INT UNSIGNED    NOT NULL DEFAULT 0,
				unanswered       INT UNSIGNED    NOT NULL DEFAULT 0,
				tokens_in        BIGINT UNSIGNED NOT NULL DEFAULT 0,
				tokens_out       BIGINT UNSIGNED NOT NULL DEFAULT 0,
				cost             DECIMAL(12,6)   NOT NULL DEFAULT 0,
				avg_latency_ms   INT UNSIGNED        NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_date_agent (date, agent_id),
				KEY idx_date (date)
			) ENGINE=InnoDB {$charset}"
		);

		// A product feature, not telemetry: this is the knowledge-gap
		// worklist, the most actionable screen in the analytics area.
		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$unanswered}` (
				id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				agent_id        BIGINT UNSIGNED NOT NULL,
				conversation_id BIGINT UNSIGNED     NULL,
				query           VARCHAR(500)    NOT NULL,
				query_hash      CHAR(64)        NOT NULL,
				best_score      DECIMAL(5,4)        NULL,
				occurrences     INT UNSIGNED    NOT NULL DEFAULT 1,
				status          VARCHAR(20)     NOT NULL DEFAULT 'open',
				resolved_by     BIGINT UNSIGNED     NULL,
				first_seen_at   DATETIME        NOT NULL,
				last_seen_at    DATETIME        NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_agent_query (agent_id, query_hash),
				KEY idx_status (status, occurrences)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);

		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$audit}` (
				id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				wp_user_id   BIGINT UNSIGNED     NULL,
				action       VARCHAR(100)    NOT NULL,
				object_type  VARCHAR(50)         NULL,
				object_id    BIGINT UNSIGNED     NULL,
				changes      JSON                NULL,
				ip_hash      CHAR(64)            NULL,
				user_agent   VARCHAR(500)        NULL,
				created_at   DATETIME        NOT NULL,
				PRIMARY KEY  (id),
				KEY idx_user (wp_user_id, created_at),
				KEY idx_action (action, created_at),
				KEY idx_object (object_type, object_id)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);

		// Only used when no persistent object cache exists. With Redis or
		// Memcached present, rate limiting never touches the database.
		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$limits}` (
				id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				bucket_key   VARCHAR(191)    NOT NULL,
				window_start DATETIME        NOT NULL,
				hits         INT UNSIGNED    NOT NULL DEFAULT 1,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_bucket_window (bucket_key, window_start),
				KEY idx_window (window_start)
			) ENGINE=InnoDB {$charset}"
		);
	}

	public function down(): void {
		$this->drop( Schema::RATE_LIMITS );
		$this->drop( Schema::AUDIT_LOG );
		$this->drop( Schema::UNANSWERED );
		$this->drop( Schema::ANALYTICS_DAILY );
		$this->drop( Schema::USAGE_EVENTS );
	}
}
