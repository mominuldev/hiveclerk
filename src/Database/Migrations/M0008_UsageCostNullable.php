<?php
/**
 * Allow usage events with no known price.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Migrations;

use Hiveclerk\Database\Migration;
use Hiveclerk\Database\Schema;

/**
 * Makes usage_events.cost nullable.
 *
 * The column shipped as NOT NULL DEFAULT 0, which forces a lie. A model
 * with no published price — a preview release, a brokered model, a
 * customer's own fine-tune — would record a cost of zero, and zero is a
 * claim that the call was free. Summed across a month of conversations
 * that quietly understates spend, and an understated spend figure is
 * worse than a missing one because nobody goes looking for it.
 *
 * NULL says "not known", which the summary layer counts and reports
 * separately.
 */
final class M0008_UsageCostNullable extends Migration {

	public function version(): int {
		return 8;
	}

	public function description(): string {
		return 'Allow a null cost on usage events';
	}

	public function up(): void {
		$usage = Schema::table( Schema::USAGE_EVENTS );

		$this->run(
			"ALTER TABLE `{$usage}` MODIFY COLUMN cost DECIMAL(12,6) NULL DEFAULT NULL"
		);

		// Rows written before this migration cannot be told apart from
		// genuinely free calls, so they are left as they are rather than
		// being rewritten to NULL on a guess.
	}

	public function down(): void {
		$usage = Schema::table( Schema::USAGE_EVENTS );

		$this->run( "UPDATE `{$usage}` SET cost = 0 WHERE cost IS NULL" );

		$this->run(
			"ALTER TABLE `{$usage}` MODIFY COLUMN cost DECIMAL(12,6) NOT NULL DEFAULT 0"
		);
	}
}
