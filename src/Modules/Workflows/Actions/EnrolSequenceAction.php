<?php
/**
 * Start an email sequence.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Actions;

use Hiveclerk\Domain\Email\EmailSequence;
use Hiveclerk\Domain\Email\SequenceRepositoryInterface;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Domain\Workflow\ActionResult;
use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\WorkflowContext;
use Hiveclerk\Modules\Email\Services\EnrolmentService;

/**
 * Hands the lead to the email module (FR-WFL-03).
 *
 * Enrolment rather than sending. Everything that makes follow-up email
 * safe on this platform lives behind `EnrolmentService::enrol()` — the
 * suppression list, the one-enrolment-per-lead rule, the unsubscribe
 * link, the hourly ceiling, the exit on reply — and an action that sent
 * its own message would have none of it. The first person to notice
 * would be a recipient who had already unsubscribed.
 *
 * A paused or draft sequence is a skip, not a failure. Pausing a
 * sequence is a normal thing to do while editing it, and a workflow that
 * marked every run failed for the afternoon somebody spent rewriting
 * their copy would be a workflow nobody trusts afterwards.
 */
final class EnrolSequenceAction extends AbstractLeadAction {

	/**
	 * Construct.
	 *
	 * @param LeadRepositoryInterface     $leads     Lead lookup.
	 * @param EnrolmentService            $enrolment Enrolment.
	 * @param SequenceRepositoryInterface $sequences Sequence lookup.
	 */
	public function __construct(
		LeadRepositoryInterface $leads,
		private readonly EnrolmentService $enrolment,
		private readonly SequenceRepositoryInterface $sequences
	) {
		parent::__construct( $leads );
	}

	/**
	 * Which action this handles.
	 *
	 * @return ActionType
	 */
	public function type(): ActionType {
		return ActionType::EnrolSequence;
	}

	/**
	 * Enrol the lead.
	 *
	 * @param Lead                 $lead    The lead.
	 * @param WorkflowContext      $context What the run knows.
	 * @param array<string, mixed> $config  Node configuration.
	 * @return ActionResult
	 */
	protected function run( Lead $lead, WorkflowContext $context, array $config ): ActionResult {
		unset( $context );

		$sequence = $this->sequence( $config );

		if ( null === $sequence ) {
			return ActionResult::failed(
				__( 'The sequence this step points at has been deleted.', 'hiveclerk' )
			);
		}

		if ( ! $sequence->isActive() ) {
			return ActionResult::skipped(
				sprintf(
					/* translators: %s: sequence name. */
					__( '%s is not active, so nobody was enrolled.', 'hiveclerk' ),
					$sequence->name
				)
			);
		}

		$enrollment = $this->enrolment->enrol( $sequence, $lead );

		if ( null === $enrollment ) {
			// Already enrolled, unsubscribed, no address, or an exit
			// condition already true. The email module owns that decision
			// and has already recorded why on the lead's timeline.
			return ActionResult::skipped(
				sprintf(
					/* translators: %s: sequence name. */
					__( 'Not enrolled in %s — already in it, or unsubscribed.', 'hiveclerk' ),
					$sequence->name
				)
			);
		}

		return ActionResult::done(
			sprintf(
				/* translators: %s: sequence name. */
				__( 'Enrolled in %s.', 'hiveclerk' ),
				$sequence->name
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
		if ( null === $this->configString( $config, 'sequence' ) ) {
			return __( 'Choose the sequence this step should start.', 'hiveclerk' );
		}

		return null === $this->sequence( $config )
			? __( 'The sequence this step points at no longer exists.', 'hiveclerk' )
			: null;
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

		return sprintf(
			/* translators: %s: sequence name. */
			__( 'Enrol the lead in %s', 'hiveclerk' ),
			$this->sequence( $config )->name ?? __( 'a deleted sequence', 'hiveclerk' )
		);
	}

	/**
	 * The configured sequence.
	 *
	 * @param array<string, mixed> $config Node configuration.
	 * @return EmailSequence|null
	 */
	private function sequence( array $config ): ?EmailSequence {
		$uuid = $this->configString( $config, 'sequence' );

		if ( null === $uuid || ! Uuid::isValid( $uuid ) ) {
			return null;
		}

		return $this->sequences->findByUuid( new Uuid( $uuid ) );
	}
}
