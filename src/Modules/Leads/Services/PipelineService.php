<?php
/**
 * Pipeline stage management.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Services;

use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStage;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;
use Hiveclerk\Modules\Leads\Support\LeadException;

/**
 * The columns of the board, and the rules about changing them (FR-LED-05).
 *
 * Stages are configuration, not data, so changes go to the audit log —
 * unlike a lead moving between them, which goes to the lead's own
 * timeline. Renaming "Qualified" to "Ready to quote" changes what every
 * report on the site means, and that belongs in the record of what was
 * done to this install.
 */
final class PipelineService {

	/**
	 * Audit action names.
	 */
	public const STAGE_CREATED  = 'lead.stage.created';
	public const STAGE_UPDATED  = 'lead.stage.updated';
	public const STAGE_DELETED  = 'lead.stage.deleted';
	public const STAGES_REORDER = 'lead.stage.reordered';
	public const RULES_UPDATED  = 'lead.scoring_rules.updated';
	public const ALERTS_UPDATED = 'lead.alerts.updated';

	/**
	 * Most columns a board may hold.
	 *
	 * A board is read left to right on one screen. Past a dozen columns
	 * it is a horizontal scroll nobody reaches the end of, which is the
	 * same as not having a pipeline.
	 */
	private const MAX_STAGES = 12;

	/**
	 * Construct.
	 *
	 * @param LeadStageRepositoryInterface $stages Stage storage.
	 * @param LeadRepositoryInterface      $leads  Lead storage.
	 * @param AuditLogger                  $audit  Audit log.
	 */
	public function __construct(
		private readonly LeadStageRepositoryInterface $stages,
		private readonly LeadRepositoryInterface $leads,
		private readonly AuditLogger $audit
	) {
	}

	/**
	 * Every stage, left to right.
	 *
	 * @return array<int, LeadStage>
	 */
	public function all(): array {
		return $this->stages->all();
	}

	/**
	 * Add a column.
	 *
	 * @param array<string, mixed> $input Cleaned fields.
	 * @return LeadStage
	 *
	 * @throws LeadException When the board is full or the name is missing.
	 */
	public function create( array $input ): LeadStage {
		$name = trim( (string) ( $input['name'] ?? '' ) );

		if ( '' === $name ) {
			throw new LeadException( __( 'Give the stage a name.', 'hiveclerk' ) );
		}

		if ( $this->stages->count() >= self::MAX_STAGES ) {
			throw new LeadException(
				sprintf(
					/* translators: %d: maximum number of pipeline stages. */
					__( 'A pipeline holds up to %d stages. Remove one before adding another.', 'hiveclerk' ),
					self::MAX_STAGES
				)
			);
		}

		$stage = new LeadStage(
			id: null,
			name: mb_substr( $name, 0, 191 ),
			slug: $this->uniqueSlug( $name ),
			color: $this->color( $input ),
			position: $this->stages->count(),
			isWon: (bool) ( $input['is_won'] ?? false ),
			isLost: (bool) ( $input['is_lost'] ?? false ),
		);

		$stage = $this->stages->save( $stage );

		$this->audit->record(
			self::STAGE_CREATED,
			array( 'name' => $stage->name ),
			'lead_stage',
			$stage->id
		);

		return $stage;
	}

	/**
	 * Change a column.
	 *
	 * The slug does not follow the name. By the time anyone renames a
	 * stage its slug is in an integration's field mapping and in whatever
	 * report the customer built, and a rename that broke both is a
	 * surprise nobody asked for.
	 *
	 * @param LeadStage            $stage The stage.
	 * @param array<string, mixed> $input Cleaned fields.
	 * @return LeadStage
	 */
	public function update( LeadStage $stage, array $input ): LeadStage {
		$name = trim( (string) ( $input['name'] ?? '' ) );

		if ( '' !== $name ) {
			$stage->name = mb_substr( $name, 0, 191 );
		}

		if ( array_key_exists( 'color', $input ) ) {
			$stage->color = $this->color( $input );
		}

		if ( array_key_exists( 'is_won', $input ) ) {
			$stage->isWon = (bool) $input['is_won'];
		}

		if ( array_key_exists( 'is_lost', $input ) ) {
			$stage->isLost = (bool) $input['is_lost'];
		}

		if ( $stage->isWon && $stage->isLost ) {
			throw new LeadException( __( 'A stage cannot be both won and lost.', 'hiveclerk' ) );
		}

		$stage = $this->stages->save( $stage );

		$this->audit->record(
			self::STAGE_UPDATED,
			array( 'name' => $stage->name ),
			'lead_stage',
			$stage->id
		);

		return $stage;
	}

	/**
	 * Remove a column, moving what was in it.
	 *
	 * Leads are never deleted with their stage. Somebody tidying their
	 * board has not asked to lose the people standing in that column, and
	 * a delete that silently took them would be discovered a quarter
	 * later.
	 *
	 * @param LeadStage $stage The stage.
	 * @param int|null  $moveTo Destination stage id, or null to unstage.
	 * @return int Leads moved.
	 *
	 * @throws LeadException When it is the last stage, or the destination is itself.
	 */
	public function delete( LeadStage $stage, ?int $moveTo = null ): int {
		if ( null === $stage->id ) {
			return 0;
		}

		if ( $this->stages->count() <= 1 ) {
			throw new LeadException(
				__( 'A pipeline needs at least one stage. Add another before removing this one.', 'hiveclerk' )
			);
		}

		if ( $moveTo === $stage->id ) {
			throw new LeadException( __( 'Leads cannot be moved into the stage being deleted.', 'hiveclerk' ) );
		}

		if ( null !== $moveTo && null === $this->stages->find( $moveTo ) ) {
			throw LeadException::notFound( __( 'That pipeline stage does not exist.', 'hiveclerk' ) );
		}

		$moved = $this->leads->reassignStage( $stage->id, $moveTo );

		$this->stages->delete( $stage->id );

		$this->audit->record(
			self::STAGE_DELETED,
			array(
				'name'        => $stage->name,
				'leads_moved' => $moved,
			),
			'lead_stage',
			$stage->id
		);

		return $moved;
	}

	/**
	 * Write a new left-to-right order.
	 *
	 * Ids naming a stage that no longer exists are dropped rather than
	 * refused: the order arrives from a board that was rendered before
	 * the drag, and a column deleted in another tab must not cost the
	 * operator the arrangement they just made.
	 *
	 * @param array<int, int> $ids Stage ids in their new order.
	 * @return array<int, LeadStage>
	 */
	public function reorder( array $ids ): array {
		$known = array();

		foreach ( $this->stages->all() as $stage ) {
			if ( null !== $stage->id ) {
				$known[ $stage->id ] = true;
			}
		}

		$ordered = array();

		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( isset( $known[ $id ] ) && ! in_array( $id, $ordered, true ) ) {
				$ordered[] = $id;
			}
		}

		// Anything the client did not mention keeps its place at the end,
		// rather than collapsing to position zero and jumping to the front.
		foreach ( array_keys( $known ) as $id ) {
			if ( ! in_array( $id, $ordered, true ) ) {
				$ordered[] = $id;
			}
		}

		$this->stages->reorder( $ordered );

		$this->audit->record( self::STAGES_REORDER, array( 'order' => $ordered ), 'lead_stage' );

		return $this->stages->all();
	}

	/**
	 * How many leads sit in each column.
	 *
	 * @param array<string, mixed> $filters Board filters.
	 * @return array<int, int>
	 */
	public function counts( array $filters = array() ): array {
		return $this->leads->countsByStage( $filters );
	}

	/**
	 * A slug nothing else is using.
	 *
	 * @param string $name Stage name.
	 * @return string
	 */
	private function uniqueSlug( string $name ): string {
		$base = sanitize_title( $name );
		$base = '' === $base ? 'stage' : $base;
		$slug = $base;
		$n    = 2;

		while ( null !== $this->stages->findBySlug( $slug ) ) {
			$slug = $base . '-' . $n;

			++$n;
		}

		return mb_substr( $slug, 0, 191 );
	}

	/**
	 * A colour, restricted to the design system's own names.
	 *
	 * A free-text colour would put a customer-chosen hex on a card that
	 * has to stay readable in both themes, and there is no contrast check
	 * that survives an arbitrary value.
	 *
	 * @param array<string, mixed> $input Cleaned fields.
	 * @return string|null
	 */
	private function color( array $input ): ?string {
		$allowed = array( 'slate', 'blue', 'green', 'amber', 'red', 'violet', 'teal' );
		$value   = strtolower( trim( (string) ( $input['color'] ?? '' ) ) );

		return in_array( $value, $allowed, true ) ? $value : null;
	}
}
