<?php
/**
 * Columns the conversations screen supervises with.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Migrations;

use Hiveclerk\Database\Migration;
use Hiveclerk\Database\Schema;

/**
 * Adds `starred` and `notes` to conversations (FR-CNV-04).
 *
 * `tags` already existed as JSON and stays that way. Notes join it rather
 * than getting a table of their own: a note is only ever read with its
 * conversation, never queried across conversations, and a table would buy
 * a join on every transcript view in exchange for a query nobody makes.
 * The cost of that choice is real and worth stating — notes cannot be
 * searched, and the JSON column caps them by length rather than by count.
 *
 * `starred` is a column, not a tag, because it is filtered on. A JSON
 * predicate cannot use an index, and the star is the one flag an operator
 * sorts the whole list by.
 */
final class M0009_ConversationSupervision extends Migration {

	public function version(): int {
		return 9;
	}

	public function description(): string {
		return 'Add starring and internal notes to conversations';
	}

	public function up(): void {
		$conversations = Schema::table( Schema::CONVERSATIONS );

		// Each change is guarded rather than batched, because the runner
		// may re-apply a migration after a partial failure and a second
		// ADD COLUMN is a hard error on both engines.
		if ( ! $this->hasColumn( Schema::CONVERSATIONS, 'starred' ) ) {
			$this->run(
				"ALTER TABLE `{$conversations}`
					ADD COLUMN starred TINYINT(1) UNSIGNED NOT NULL DEFAULT 0"
			);
		}

		if ( ! $this->hasColumn( Schema::CONVERSATIONS, 'notes' ) ) {
			$this->run( "ALTER TABLE `{$conversations}` ADD COLUMN notes JSON NULL" );
		}

		// Starred conversations are read as a filter over a date-ordered
		// list, so the index carries the sort column with it.
		if ( ! $this->hasIndex( Schema::CONVERSATIONS, 'idx_starred' ) ) {
			$this->run(
				"ALTER TABLE `{$conversations}` ADD INDEX idx_starred (starred, started_at)"
			);
		}
	}

	public function down(): void {
		$conversations = Schema::table( Schema::CONVERSATIONS );

		if ( $this->hasIndex( Schema::CONVERSATIONS, 'idx_starred' ) ) {
			$this->run( "ALTER TABLE `{$conversations}` DROP INDEX idx_starred" );
		}

		foreach ( array( 'starred', 'notes' ) as $column ) {
			if ( $this->hasColumn( Schema::CONVERSATIONS, $column ) ) {
				$this->run( "ALTER TABLE `{$conversations}` DROP COLUMN `{$column}`" );
			}
		}
	}
}
