<?php
/**
 * Knowledge base tables.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Migrations;

use Hiveclerk\Database\Migration;
use Hiveclerk\Database\Schema;

/**
 * Creates the retrieval tables.
 */
final class M0002_Knowledge extends Migration {

	public function version(): int {
		return 2;
	}

	public function description(): string {
		return 'Create knowledge base tables';
	}

	public function up(): void {
		$sources    = Schema::table( Schema::KNOWLEDGE_SOURCES );
		$documents  = Schema::table( Schema::DOCUMENTS );
		$chunks     = Schema::table( Schema::CHUNKS );
		$embeddings = Schema::table( Schema::EMBEDDINGS );
		$charset    = $this->charset();

		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$sources}` (
				id                BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
				uuid              CHAR(36)          NOT NULL,
				name              VARCHAR(191)      NOT NULL,
				type              VARCHAR(32)       NOT NULL,
				status            VARCHAR(20)       NOT NULL DEFAULT 'pending',
				config            JSON                  NULL,
				embed_provider    VARCHAR(32)           NULL,
				embed_model       VARCHAR(64)           NULL,
				embed_dimensions  SMALLINT UNSIGNED     NULL,
				document_count    INT UNSIGNED      NOT NULL DEFAULT 0,
				chunk_count       INT UNSIGNED      NOT NULL DEFAULT 0,
				token_count       BIGINT UNSIGNED   NOT NULL DEFAULT 0,
				sync_schedule     VARCHAR(20)       NOT NULL DEFAULT 'manual',
				last_synced_at    DATETIME              NULL,
				next_sync_at      DATETIME              NULL,
				last_error        TEXT                  NULL,
				progress          JSON                  NULL,
				created_at        DATETIME          NOT NULL,
				updated_at        DATETIME          NOT NULL,
				deleted_at        DATETIME              NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_uuid (uuid),
				KEY idx_status (status, deleted_at),
				KEY idx_next_sync (next_sync_at, sync_schedule)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);

		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$documents}` (
				id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				source_id     BIGINT UNSIGNED NOT NULL,
				external_id   VARCHAR(191)        NULL,
				url           VARCHAR(500)        NULL,
				title         VARCHAR(500)        NULL,
				content       LONGTEXT            NULL,
				content_hash  CHAR(64)        NOT NULL,
				language      VARCHAR(10)         NULL,
				metadata      JSON                NULL,
				token_count   INT UNSIGNED    NOT NULL DEFAULT 0,
				chunk_count   INT UNSIGNED    NOT NULL DEFAULT 0,
				status        VARCHAR(20)     NOT NULL DEFAULT 'pending',
				indexed_at    DATETIME            NULL,
				created_at    DATETIME        NOT NULL,
				updated_at    DATETIME        NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_source_external (source_id, external_id),
				KEY idx_source (source_id, status),
				KEY idx_hash (content_hash)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);

		/*
		 * source_id is denormalised onto chunks and embeddings on purpose:
		 * retrieval scopes by the clerk's assigned sources on every single
		 * query, and carrying it here removes a join from the hottest path
		 * in the product.
		 */
		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$chunks}` (
				id            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
				document_id   BIGINT UNSIGNED   NOT NULL,
				source_id     BIGINT UNSIGNED   NOT NULL,
				chunk_index   INT UNSIGNED      NOT NULL,
				content       TEXT              NOT NULL,
				content_hash  CHAR(64)          NOT NULL,
				heading_path  VARCHAR(500)          NULL,
				token_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				char_start    INT UNSIGNED          NULL,
				char_end      INT UNSIGNED          NULL,
				created_at    DATETIME          NOT NULL,
				PRIMARY KEY  (id),
				KEY idx_document (document_id, chunk_index),
				KEY idx_source (source_id),
				KEY idx_hash (content_hash),
				FULLTEXT KEY ft_content (content)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);

		/*
		 * embedding_bits is the 1-bit-per-dimension quantisation scanned by
		 * stage 1 of retrieval; embedding_f32 is the exact vector loaded only
		 * for the survivors. Keeping them in one narrow table means the hot
		 * scan touches 192 bytes per row instead of 6 KB.
		 */
		$this->run(
			"CREATE TABLE IF NOT EXISTS `{$embeddings}` (
				id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
				chunk_id        BIGINT UNSIGNED   NOT NULL,
				source_id       BIGINT UNSIGNED   NOT NULL,
				provider        VARCHAR(32)       NOT NULL,
				model           VARCHAR(64)       NOT NULL,
				dimensions      SMALLINT UNSIGNED NOT NULL,
				embedding_f32   LONGBLOB          NOT NULL,
				embedding_bits  VARBINARY(256)    NOT NULL,
				norm            FLOAT             NOT NULL DEFAULT 0,
				created_at      DATETIME          NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_chunk_model (chunk_id, provider, model),
				KEY idx_source_scan (source_id, id)
			) ENGINE=InnoDB {$charset} ROW_FORMAT=DYNAMIC"
		);
	}

	public function down(): void {
		$this->drop( Schema::EMBEDDINGS );
		$this->drop( Schema::CHUNKS );
		$this->drop( Schema::DOCUMENTS );
		$this->drop( Schema::KNOWLEDGE_SOURCES );
	}
}
