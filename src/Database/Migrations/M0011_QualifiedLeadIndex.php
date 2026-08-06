<?php
/**
 * The index behind the qualified-lead figure.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Migrations;

use Hiveclerk\Database\Migration;
use Hiveclerk\Database\Schema;

/**
 * Covers the one analytics query whose cost grows with all history.
 *
 * Every figure on the dashboard is scoped to a day except this one. To
 * count leads that qualified today, `RollupRepository::qualifiedCounts()`
 * first has to find, for every lead that has *ever* crossed the score
 * threshold, the event where it happened:
 *
 *     SELECT lead_id, MIN(id) FROM hvc_lead_scores
 *     WHERE score_after >= ? GROUP BY lead_id
 *
 * The day only enters afterwards, as a filter on the joined row. So the
 * subquery reads the whole table, and it does so once per day processed —
 * seven times an hour on a caught-up site, and once more on every
 * dashboard load, which is what put it inside the 400 ms admin budget.
 *
 * `(lead_id, score_after, id)` covers it: every column the subquery reads
 * is in the index, so the rows themselves are never visited.
 *
 * Measured rather than assumed, and the gain is narrower than it first
 * looks. `idx_lead (lead_id, created_at)` already led with lead_id, so
 * the grouping was following index order and there was no temporary
 * table — `EXPLAIN` before this migration reads
 * `type=index key=idx_lead Extra=Using where`. What it was doing was
 * visiting the row behind every index entry to read `score_after`, which
 * the index does not carry. Afterwards it reads
 * `type=index key=idx_qualified Extra=Using where; Using index`: same
 * traversal, no row lookups.
 *
 * So this removes one random read per score event, not a table scan. On a
 * site with a long lead history that is the difference between an index
 * walk and an index walk plus a few hundred thousand page fetches; on a
 * small one it is close to nothing, and the EXPLAIN above was taken on a
 * development table of sixteen rows, where only the plan shape is
 * meaningful.
 *
 * The caching added alongside this makes the query rarer. This makes it
 * cheaper. Neither is a substitute for the other: a cache with a
 * one-minute life still leaves the hourly rollup paying full price.
 */
final class M0011_QualifiedLeadIndex extends Migration {

	public function version(): int {
		return 11;
	}

	public function description(): string {
		return 'Cover the qualified-lead lookup on hvc_lead_scores';
	}

	public function up(): void {
		if ( $this->hasIndex( Schema::LEAD_SCORES, 'idx_qualified' ) ) {
			return;
		}

		$scores = Schema::table( Schema::LEAD_SCORES );

		$this->run( "ALTER TABLE `{$scores}` ADD INDEX idx_qualified (lead_id, score_after, id)" );
	}

	public function down(): void {
		if ( ! $this->hasIndex( Schema::LEAD_SCORES, 'idx_qualified' ) ) {
			return;
		}

		$scores = Schema::table( Schema::LEAD_SCORES );

		$this->run( "ALTER TABLE `{$scores}` DROP INDEX idx_qualified" );
	}
}
