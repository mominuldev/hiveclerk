<?php
/**
 * Adjust a lead's score.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Actions;

use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Workflow\ActionResult;
use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\WorkflowContext;
use Hiveclerk\Modules\Leads\Services\ScoringService;

/**
 * Adds or subtracts points, with the workflow's name as the reason.
 *
 * The adjustment goes through the scoring service so it lands on the
 * score breakdown like any other. A score that changed with no line
 * explaining it is the single most confusing thing this product can show
 * an operator, and "Workflow: Cold lead revival" on the breakdown is the
 * difference between a mystery and a setting.
 */
final class AdjustScoreAction extends AbstractLeadAction {

	/**
	 * The largest single adjustment a node may make.
	 *
	 * Scores are a 0–100 scale. A step that moves one by more than half
	 * the scale is not adjusting a score, it is overwriting it, and the
	 * ceiling makes a mistyped 500 fail on save rather than silently
	 * qualifying everybody the workflow touches.
	 */
	public const MAX_POINTS = 50;

	/**
	 * Construct.
	 *
	 * @param LeadRepositoryInterface $leads   Lead lookup.
	 * @param ScoringService          $scoring Scoring.
	 */
	public function __construct(
		LeadRepositoryInterface $leads,
		private readonly ScoringService $scoring
	) {
		parent::__construct( $leads );
	}

	/**
	 * Which action this handles.
	 *
	 * @return ActionType
	 */
	public function type(): ActionType {
		return ActionType::AdjustScore;
	}

	/**
	 * Apply the adjustment.
	 *
	 * @param Lead                 $lead    The lead.
	 * @param WorkflowContext      $context What the run knows.
	 * @param array<string, mixed> $config  Node configuration.
	 * @return ActionResult
	 */
	protected function run( Lead $lead, WorkflowContext $context, array $config ): ActionResult {
		$points = $this->points( $config );

		if ( 0 === $points ) {
			return ActionResult::skipped( __( 'The adjustment was zero points.', 'hiveclerk' ) );
		}

		$reason = $this->configString( $config, 'reason' )
			?? sprintf(
				/* translators: %s: workflow name. */
				__( 'Workflow: %s', 'hiveclerk' ),
				$context->string( 'workflow.name' ) ?? __( 'automation', 'hiveclerk' )
			);

		$applied = $this->scoring->applyManualAdjustment( $lead, $points, $reason );

		if ( ! $applied ) {
			return ActionResult::skipped( __( 'The score was left alone.', 'hiveclerk' ) );
		}

		return ActionResult::done(
			sprintf(
				/* translators: 1: signed points, 2: resulting score. */
				__( '%1$s points → score %2$d', 'hiveclerk' ),
				$points > 0 ? '+' . $points : (string) $points,
				$lead->score
			),
			array( 'lead.score' => $lead->score )
		);
	}

	/**
	 * Whether the node is complete.
	 *
	 * @param array<string, mixed> $config Node configuration.
	 * @return string|null
	 */
	public function validate( array $config ): ?string {
		$raw = $this->configInt( $config, 'points' );

		if ( null === $raw || 0 === $raw ) {
			return __( 'Set how many points to add or subtract.', 'hiveclerk' );
		}

		if ( abs( $raw ) > self::MAX_POINTS ) {
			return sprintf(
				/* translators: %d: maximum points. */
				__( 'A single step can move a score by at most %d points.', 'hiveclerk' ),
				self::MAX_POINTS
			);
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

		$points = $this->points( $config );

		return sprintf(
			/* translators: %s: signed points. */
			__( 'Adjust the score by %s points', 'hiveclerk' ),
			$points > 0 ? '+' . $points : (string) $points
		);
	}

	/**
	 * The clamped adjustment.
	 *
	 * @param array<string, mixed> $config Node configuration.
	 * @return int
	 */
	private function points( array $config ): int {
		$raw = $this->configInt( $config, 'points', 0 ) ?? 0;

		return max( -self::MAX_POINTS, min( self::MAX_POINTS, $raw ) );
	}
}
