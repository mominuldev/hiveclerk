<?php
/**
 * Unknown money stops being reported as zero.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Migrations;

use Hiveclerk\Database\Migration;
use Hiveclerk\Database\Schema;

/**
 * Finishes what M0008 started, on the three places it did not reach.
 *
 * M0008 made `usage_events.cost` nullable because a model with no
 * published price recorded a cost of zero, and zero is a claim that the
 * call was free. It was right, and it covered one of four sinks the same
 * call feeds. The other three kept the lie:
 *
 * - `messages.cost` — `NOT NULL DEFAULT 0`, written from
 *   `Completion::$reportedCost` with a `?? 0.0` in front of it.
 * - `conversations.total_cost` — accumulates that zero.
 * - `analytics_daily.cost` — summed from `usage_events`, where SQL `SUM`
 *   skips NULLs, so unpriced calls vanish into a number that looks
 *   complete. The honest signal existed at the source and was dropped at
 *   the rollup boundary.
 *
 * ## Why a total is a sum and a count, not a nullable
 *
 * `total_cost` is an accumulator, and nulling it when one message in a
 * conversation is unpriced throws away everything that *is* known. "At
 * least this much, plus some unknown calls" is the honest reading, and it
 * takes two columns to say.
 *
 * That shape is not invented here: `UsageRepository` already reports
 * `SUM(cost IS NULL) AS unpriced` beside its sum. This gives conversations
 * and the daily rollup the same pair, so the three layers finally agree.
 *
 * ## What is not rewritten
 *
 * Existing rows stay as they are, for the same reason M0008 left its own
 * alone: a zero written before this migration cannot be told apart from a
 * genuinely free call, and guessing would replace a known-wrong number
 * with an unknown-wrong one. The counters start at zero and describe
 * everything after this point.
 */
final class M0012_UnknownCost extends Migration {

	public function version(): int {
		return 12;
	}

	public function description(): string {
		return 'Record an unknown cost as unknown on messages, conversations and rollups';
	}

	public function up(): void {
		$messages      = Schema::table( Schema::MESSAGES );
		$conversations = Schema::table( Schema::CONVERSATIONS );
		$daily         = Schema::table( Schema::ANALYTICS_DAILY );

		$this->run(
			"ALTER TABLE `{$messages}` MODIFY COLUMN cost DECIMAL(12,6) NULL DEFAULT NULL"
		);

		if ( ! $this->hasColumn( Schema::CONVERSATIONS, 'unpriced_calls' ) ) {
			$this->run(
				"ALTER TABLE `{$conversations}`
					ADD COLUMN unpriced_calls SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER total_cost"
			);
		}

		if ( ! $this->hasColumn( Schema::ANALYTICS_DAILY, 'unpriced' ) ) {
			$this->run(
				"ALTER TABLE `{$daily}`
					ADD COLUMN unpriced INT UNSIGNED NOT NULL DEFAULT 0 AFTER cost"
			);
		}
	}

	public function down(): void {
		$messages      = Schema::table( Schema::MESSAGES );
		$conversations = Schema::table( Schema::CONVERSATIONS );
		$daily         = Schema::table( Schema::ANALYTICS_DAILY );

		$this->run( "UPDATE `{$messages}` SET cost = 0 WHERE cost IS NULL" );
		$this->run(
			"ALTER TABLE `{$messages}` MODIFY COLUMN cost DECIMAL(12,6) NOT NULL DEFAULT 0"
		);

		if ( $this->hasColumn( Schema::CONVERSATIONS, 'unpriced_calls' ) ) {
			$this->run( "ALTER TABLE `{$conversations}` DROP COLUMN unpriced_calls" );
		}

		if ( $this->hasColumn( Schema::ANALYTICS_DAILY, 'unpriced' ) ) {
			$this->run( "ALTER TABLE `{$daily}` DROP COLUMN unpriced" );
		}
	}
}
