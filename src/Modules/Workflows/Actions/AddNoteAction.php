<?php
/**
 * Write a note on the lead timeline.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Actions;

use Hiveclerk\Domain\Lead\ActivityType;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Workflow\ActionResult;
use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\WorkflowContext;
use Hiveclerk\Modules\Leads\Services\LeadService;
use Hiveclerk\Modules\Workflows\Support\Placeholders;

/**
 * The one action that changes nothing but what a human reads.
 *
 * Useful on its own — "flag this for the sales team" — and useful as the
 * safe first step of a workflow somebody is still testing. A graph whose
 * only action is a note can be activated against live data without
 * anybody receiving anything, which is how an operator finds out whether
 * their conditions actually match before attaching an email to them.
 */
final class AddNoteAction extends AbstractLeadAction {

	/**
	 * Construct.
	 *
	 * @param LeadRepositoryInterface $leads   Lead lookup.
	 * @param LeadService             $service Lead operations.
	 */
	public function __construct(
		LeadRepositoryInterface $leads,
		private readonly LeadService $service
	) {
		parent::__construct( $leads );
	}

	/**
	 * Which action this handles.
	 *
	 * @return ActionType
	 */
	public function type(): ActionType {
		return ActionType::AddNote;
	}

	/**
	 * Write the note.
	 *
	 * @param Lead                 $lead    The lead.
	 * @param WorkflowContext      $context What the run knows.
	 * @param array<string, mixed> $config  Node configuration.
	 * @return ActionResult
	 */
	protected function run( Lead $lead, WorkflowContext $context, array $config ): ActionResult {
		$text = $this->configString( $config, 'note' );

		if ( null === $text ) {
			return ActionResult::failed( __( 'This step has no note to write.', 'hiveclerk' ) );
		}

		$body = Placeholders::fill( $text, $context );

		$this->service->note(
			$lead,
			ActivityType::NoteAdded,
			sprintf(
				/* translators: %s: workflow name. */
				__( 'Workflow: %s', 'hiveclerk' ),
				$context->string( 'workflow.name' ) ?? __( 'automation', 'hiveclerk' )
			),
			$body,
			null,
			array( 'workflow_run' => $context->int( 'run.id' ) )
		);

		return ActionResult::done( $body );
	}

	/**
	 * Whether the node is complete.
	 *
	 * @param array<string, mixed> $config Node configuration.
	 * @return string|null
	 */
	public function validate( array $config ): ?string {
		return null === $this->configString( $config, 'note' )
			? __( 'Write the note this step should leave.', 'hiveclerk' )
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
		$text = $this->configString( $config, 'note' );

		return sprintf(
			/* translators: %s: the note. */
			__( 'Add the note “%s”', 'hiveclerk' ),
			null === $text ? __( '(empty)', 'hiveclerk' ) : Placeholders::fill( $text, $context )
		);
	}
}
