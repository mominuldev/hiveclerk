<?php
/**
 * Push a lead to the connected CRMs.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Actions;

use Hiveclerk\Domain\Integration\SyncTrigger;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Workflow\ActionResult;
use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\WorkflowContext;
use Hiveclerk\Modules\Integrations\Services\SyncService;

/**
 * Delegates to the sync service, which queues rather than posts.
 *
 * `dispatch()` enqueues one job per connector that accepts this lead, so
 * this action returns in microseconds and a CRM having a bad afternoon
 * cannot stall the tick that every other workflow on the site is sharing.
 * The retry policy, the field mapping and the sync log all belong to the
 * Integrations module and stay there.
 *
 * The `Manual` trigger is the honest label: a person built this workflow
 * and pointed it at the CRM, which is closer to pressing Sync on a lead
 * than to any of the automatic triggers the connector configuration
 * offers. It also means a connector configured to sync only qualified
 * leads still applies its own rule — the workflow asks, it does not
 * override.
 */
final class SyncCrmAction extends AbstractLeadAction {

	/**
	 * Construct.
	 *
	 * @param LeadRepositoryInterface $leads Lead lookup.
	 * @param SyncService             $sync  Outbound sync.
	 */
	public function __construct(
		LeadRepositoryInterface $leads,
		private readonly SyncService $sync
	) {
		parent::__construct( $leads );
	}

	/**
	 * Which action this handles.
	 *
	 * @return ActionType
	 */
	public function type(): ActionType {
		return ActionType::SyncCrm;
	}

	/**
	 * Queue the pushes.
	 *
	 * @param Lead                 $lead    The lead.
	 * @param WorkflowContext      $context What the run knows.
	 * @param array<string, mixed> $config  Node configuration.
	 * @return ActionResult
	 */
	protected function run( Lead $lead, WorkflowContext $context, array $config ): ActionResult {
		unset( $context, $config );

		$queued = $this->sync->dispatch( $lead, SyncTrigger::Manual );

		if ( 0 === $queued ) {
			return ActionResult::skipped(
				__( 'No connected CRM accepted this lead.', 'hiveclerk' )
			);
		}

		return ActionResult::done(
			sprintf(
				/* translators: %d: number of connectors. */
				_n( 'Queued for %d connector.', 'Queued for %d connectors.', $queued, 'hiveclerk' ),
				$queued
			)
		);
	}

	/**
	 * Whether the node is complete.
	 *
	 * Nothing to configure: which connectors receive a lead is a question
	 * the Integrations screen already answers, and asking it again here
	 * would give a site two places to change one thing.
	 *
	 * @param array<string, mixed> $config Node configuration.
	 * @return string|null
	 */
	public function validate( array $config ): ?string {
		unset( $config );

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
		unset( $context, $config );

		return __( 'Push the lead to every connected CRM that accepts it', 'hiveclerk' );
	}
}
