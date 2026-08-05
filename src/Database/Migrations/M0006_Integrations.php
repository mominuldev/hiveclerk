<?php
/**
 * Integration tables.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Migrations;

use Hiveclerk\Database\Migration;
use Hiveclerk\Database\Schema;

/**
 * Creates the CRM connector and sync-log tables.
 */
final class M0006_Integrations extends Migration {

	public function version(): int {
		return 6;
	}

	public function description(): string {
		return 'Create integration tables';
	}

	public function up(): void {
		$integrations = Schema::table( Schema::INTEGRATIONS );
		$log          = Schema::table( Schema::INTEGRATION_LOG );
		$charset      = $this->charset();

		// credentials holds AES-256-GCM ciphertext. Nothing in this column is
		// ever returned to a browser.
		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$integrations}` (
				id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				provider          VARCHAR(50)     NOT NULL,
				name              VARCHAR(191)        NULL,
				status            VARCHAR(20)     NOT NULL DEFAULT 'disconnected',
				credentials       TEXT                NULL,
				token_expires_at  DATETIME            NULL,
				field_mapping     JSON                NULL,
				sync_config       JSON                NULL,
				last_sync_at      DATETIME            NULL,
				last_error        TEXT                NULL,
				error_count       INT UNSIGNED    NOT NULL DEFAULT 0,
				created_at        DATETIME        NOT NULL,
				updated_at        DATETIME        NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_provider (provider),
				KEY idx_status (status)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);

		// request_summary is redacted before it is written; credentials must
		// never reach a log row.
		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$log}` (
				id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
				integration_id  BIGINT UNSIGNED   NOT NULL,
				lead_id         BIGINT UNSIGNED       NULL,
				operation       VARCHAR(50)       NOT NULL,
				status          VARCHAR(20)       NOT NULL,
				attempt         TINYINT UNSIGNED  NOT NULL DEFAULT 1,
				external_id     VARCHAR(191)          NULL,
				request_summary JSON                  NULL,
				response_code   SMALLINT UNSIGNED     NULL,
				error           TEXT                  NULL,
				next_retry_at   DATETIME              NULL,
				created_at      DATETIME          NOT NULL,
				PRIMARY KEY  (id),
				KEY idx_integration (integration_id, created_at),
				KEY idx_lead (lead_id),
				KEY idx_retry (status, next_retry_at)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);
	}

	public function down(): void {
		$this->drop( Schema::INTEGRATION_LOG );
		$this->drop( Schema::INTEGRATIONS );
	}
}
