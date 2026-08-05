<?php
/**
 * Pipeline stage entity.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

use DateTimeImmutable;

/**
 * One column of the pipeline board (FR-LED-05).
 *
 * `isWon` and `isLost` are flags rather than a position convention. A
 * customer who names their last column "Invoiced" still needs the
 * dashboard to know that landing there is a win, and inferring it from
 * "the rightmost column" breaks the first time somebody adds a column
 * after it.
 */
final class LeadStage {

	/**
	 * Construct.
	 *
	 * @param int|null               $id        Storage id.
	 * @param string                 $name      Display name.
	 * @param string                 $slug      Stable machine name.
	 * @param string|null            $color     Accent token or hex from the picker.
	 * @param int                    $position  Left-to-right order.
	 * @param bool                   $isWon     Landing here is a conversion.
	 * @param bool                   $isLost    Landing here ends the lead.
	 * @param DateTimeImmutable|null $createdAt Creation time, UTC.
	 */
	public function __construct(
		public ?int $id,
		public string $name,
		public string $slug,
		public ?string $color = null,
		public int $position = 0,
		public bool $isWon = false,
		public bool $isLost = false,
		public ?DateTimeImmutable $createdAt = null,
	) {
	}

	/**
	 * Whether this stage closes the lead either way.
	 *
	 * @return bool
	 */
	public function isTerminal(): bool {
		return $this->isWon || $this->isLost;
	}

	/**
	 * The status a lead takes on when it lands here, or null to leave it alone.
	 *
	 * Only the terminal columns speak for the status. A customer moving a
	 * card into "Demo booked" has said nothing about whether the lead is
	 * qualified, and guessing on their behalf would overwrite a judgement
	 * the scoring rules made.
	 *
	 * @return LeadStatus|null
	 */
	public function impliedStatus(): ?LeadStatus {
		if ( $this->isWon ) {
			return LeadStatus::Converted;
		}

		if ( $this->isLost ) {
			return LeadStatus::Lost;
		}

		return null;
	}

	/**
	 * The stages a site starts with.
	 *
	 * Seeded rather than left empty. An empty board is not an invitation
	 * — it is a screen where the first lead has nowhere to land, and the
	 * five columns below are what every customer builds anyway.
	 *
	 * @return array<int, array{name: string, slug: string, color: string, is_won: bool, is_lost: bool}>
	 */
	public static function defaults(): array {
		return array(
			array(
				'name'    => 'New',
				'slug'    => 'new',
				'color'   => 'slate',
				'is_won'  => false,
				'is_lost' => false,
			),
			array(
				'name'    => 'Contacted',
				'slug'    => 'contacted',
				'color'   => 'blue',
				'is_won'  => false,
				'is_lost' => false,
			),
			array(
				'name'    => 'Qualified',
				'slug'    => 'qualified',
				'color'   => 'amber',
				'is_won'  => false,
				'is_lost' => false,
			),
			array(
				'name'    => 'Won',
				'slug'    => 'won',
				'color'   => 'green',
				'is_won'  => true,
				'is_lost' => false,
			),
			array(
				'name'    => 'Lost',
				'slug'    => 'lost',
				'color'   => 'red',
				'is_won'  => false,
				'is_lost' => true,
			),
		);
	}
}
