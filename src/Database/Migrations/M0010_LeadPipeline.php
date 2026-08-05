<?php
/**
 * The pipeline's starting state and the indexes the board reads by.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Database\Migrations;

use Hiveclerk\Database\Migration;
use Hiveclerk\Database\Schema;
use Hiveclerk\Domain\Lead\LeadStage;

/**
 * Seeds the default stages and indexes the two columns the board filters on.
 *
 * The seed is here rather than in a service that checks on boot for one
 * reason: a customer who deletes a column means it. A bootstrap check
 * that recreates missing stages would put "Lost" back every time they
 * removed it, and they would have no way to tell the product they meant
 * it. A migration runs once, at version 10, and never again.
 *
 * Both indexes are additions to the shape in `docs/07 §5.1`. The board
 * filters by capturing clerk and orders by recency, and neither of those
 * paths had an index — that is not a schema disagreement, it is the two
 * access patterns the pipeline screen introduced.
 */
final class M0010_LeadPipeline extends Migration {

	public function version(): int {
		return 10;
	}

	public function description(): string {
		return 'Seed pipeline stages and index the lead board';
	}

	public function up(): void {
		$leads = Schema::table( Schema::LEADS );

		// `source` holds the slug of the clerk that captured the lead, and
		// the pipeline's clerk filter is a straight equality on it.
		if ( ! $this->hasIndex( Schema::LEADS, 'idx_source' ) ) {
			$this->run( "ALTER TABLE `{$leads}` ADD INDEX idx_source (source)" );
		}

		// The table view's default sort. Without it, "most recently active
		// first" is a filesort over every lead on the site.
		if ( ! $this->hasIndex( Schema::LEADS, 'idx_last_active' ) ) {
			$this->run( "ALTER TABLE `{$leads}` ADD INDEX idx_last_active (last_active_at)" );
		}

		if ( $this->rowCount( Schema::LEAD_STAGES ) > 0 ) {
			return;
		}

		$position = 0;
		$now      = gmdate( 'Y-m-d H:i:s' );

		foreach ( LeadStage::defaults() as $stage ) {
			$this->insert(
				Schema::LEAD_STAGES,
				array(
					'name'       => $stage['name'],
					'slug'       => $stage['slug'],
					'color'      => $stage['color'],
					'position'   => $position,
					'is_won'     => $stage['is_won'] ? 1 : 0,
					'is_lost'    => $stage['is_lost'] ? 1 : 0,
					'created_at' => $now,
				)
			);

			++$position;
		}
	}

	public function down(): void {
		$leads = Schema::table( Schema::LEADS );

		foreach ( array( 'idx_source', 'idx_last_active' ) as $index ) {
			if ( $this->hasIndex( Schema::LEADS, $index ) ) {
				$this->run( "ALTER TABLE `{$leads}` DROP INDEX `{$index}`" );
			}
		}

		// The seeded rows are deliberately not removed. By the time anyone
		// rolls this back there are leads sitting in those stages, and
		// deleting the columns out from under them would strand every card
		// on the board with no way to tell which one it was in.
	}
}
