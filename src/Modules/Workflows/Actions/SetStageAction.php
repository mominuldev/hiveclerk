<?php
/**
 * Move a lead to a pipeline stage.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Actions;

use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;
use Hiveclerk\Domain\Workflow\ActionResult;
use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\WorkflowContext;
use Hiveclerk\Modules\Leads\Services\LeadService;

/**
 * Moves the card, exactly as dragging it would (FR-WFL-03).
 *
 * Delegated to `LeadService::moveToStage()` rather than writing the
 * column, so the implied status change, the timeline entry and the
 * `hiveclerk/lead/stage_changed` event all happen. Writing `stage_id`
 * directly would move the card and leave every other part of the product
 * believing it had not moved.
 */
final class SetStageAction extends AbstractLeadAction {

	/**
	 * Construct.
	 *
	 * @param LeadRepositoryInterface      $leads   Lead lookup.
	 * @param LeadService                  $service Lead operations.
	 * @param LeadStageRepositoryInterface $stages  Stage lookup.
	 */
	public function __construct(
		LeadRepositoryInterface $leads,
		private readonly LeadService $service,
		private readonly LeadStageRepositoryInterface $stages
	) {
		parent::__construct( $leads );
	}

	/**
	 * Which action this handles.
	 *
	 * @return ActionType
	 */
	public function type(): ActionType {
		return ActionType::SetStage;
	}

	/**
	 * Move the lead.
	 *
	 * @param Lead                 $lead    The lead.
	 * @param WorkflowContext      $context What the run knows.
	 * @param array<string, mixed> $config  Node configuration.
	 * @return ActionResult
	 */
	protected function run( Lead $lead, WorkflowContext $context, array $config ): ActionResult {
		unset( $context );

		$stageId = $this->configInt( $config, 'stage_id' );

		if ( null === $stageId ) {
			return ActionResult::failed( __( 'No stage was chosen on this step.', 'hiveclerk' ) );
		}

		$stage = $this->stages->find( $stageId );

		if ( null === $stage ) {
			return ActionResult::failed(
				__( 'The stage this step points at has been deleted.', 'hiveclerk' )
			);
		}

		if ( $lead->stageId === $stageId ) {
			return ActionResult::skipped(
				sprintf(
					/* translators: %s: stage name. */
					__( 'Already in %s.', 'hiveclerk' ),
					$stage->name
				)
			);
		}

		$this->service->moveToStage( $lead, $stageId );

		return ActionResult::done(
			sprintf(
				/* translators: %s: stage name. */
				__( 'Moved to %s.', 'hiveclerk' ),
				$stage->name
			),
			array(
				'lead.stage_id' => $stageId,
				'lead.stage'    => $stage->name,
			)
		);
	}

	/**
	 * Whether the node is complete.
	 *
	 * @param array<string, mixed> $config Node configuration.
	 * @return string|null
	 */
	public function validate( array $config ): ?string {
		$stageId = $this->configInt( $config, 'stage_id' );

		if ( null === $stageId ) {
			return __( 'Choose the stage to move the lead into.', 'hiveclerk' );
		}

		if ( null === $this->stages->find( $stageId ) ) {
			return __( 'The stage this step points at no longer exists.', 'hiveclerk' );
		}

		return null;
	}

	/**
	 * What it would do.
	 *
	 * @param WorkflowContext      $context What the run knows.
	 * @param array<string, mixed> $config  Node configuration.
	 * @return string
	 */
	public function describe( WorkflowContext $context, array $config ): string {
		unset( $context );

		$stageId = $this->configInt( $config, 'stage_id' );
		$stage   = null === $stageId ? null : $this->stages->find( $stageId );

		return sprintf(
			/* translators: %s: stage name. */
			__( 'Move the lead to %s', 'hiveclerk' ),
			$stage->name ?? __( 'a stage that no longer exists', 'hiveclerk' )
		);
	}
}
