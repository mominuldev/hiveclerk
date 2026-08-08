<?php
/**
 * Shared behaviour for actions that need a lead.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Actions;

use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Workflow\ActionHandlerInterface;
use Hiveclerk\Domain\Workflow\ActionResult;
use Hiveclerk\Domain\Workflow\WorkflowContext;

/**
 * Resolves the run's lead, or skips.
 *
 * Skipped rather than failed when the lead has gone. A lead deleted by a
 * privacy erasure request between the trigger and the action is not a
 * broken workflow — it is the erasure working — and a failed run in that
 * case would put a red row on the operator's screen for something they
 * are legally obliged to have done.
 */
abstract class AbstractLeadAction implements ActionHandlerInterface {

	/**
	 * Construct.
	 *
	 * @param LeadRepositoryInterface $leads Lead lookup.
	 */
	public function __construct( protected readonly LeadRepositoryInterface $leads ) {
	}

	/**
	 * Do the work with a resolved lead.
	 *
	 * @param Lead                 $lead    The lead.
	 * @param WorkflowContext      $context What the run knows.
	 * @param array<string, mixed> $config  Node configuration.
	 * @return ActionResult
	 */
	abstract protected function run( Lead $lead, WorkflowContext $context, array $config ): ActionResult;

	/**
	 * Resolve the lead, then act.
	 *
	 * @param WorkflowContext      $context What the run knows.
	 * @param array<string, mixed> $config  Node configuration.
	 * @return ActionResult
	 */
	public function execute( WorkflowContext $context, array $config ): ActionResult {
		$lead = $this->lead( $context );

		if ( null === $lead ) {
			return ActionResult::skipped(
				__( 'There is no lead on this run any more, so nothing was done.', 'hiveclerk' )
			);
		}

		try {
			return $this->run( $lead, $context, $config );
		} catch ( \Throwable $e ) {
			// Actions run inside a batch job. An exception escaping here
			// would take every other customer's run in the same tick with
			// it, so the failure is confined to the run that caused it.
			return ActionResult::failed( $e->getMessage() );
		}
	}

	/**
	 * The lead this run is about.
	 *
	 * @param WorkflowContext $context What the run knows.
	 * @return Lead|null
	 */
	protected function lead( WorkflowContext $context ): ?Lead {
		$id = $context->int( 'lead.id' );

		return null === $id ? null : $this->leads->find( $id );
	}

	/**
	 * A trimmed string from configuration.
	 *
	 * @param array<string, mixed> $config Node configuration.
	 * @param string               $key    Key.
	 * @return string|null
	 */
	protected function configString( array $config, string $key ): ?string {
		$value = $config[ $key ] ?? null;

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		return trim( $value );
	}

	/**
	 * An integer from configuration.
	 *
	 * @param array<string, mixed> $config   Node configuration.
	 * @param string               $key      Key.
	 * @param int|null             $fallback Value when absent.
	 * @return int|null
	 */
	protected function configInt( array $config, string $key, ?int $fallback = null ): ?int {
		$value = $config[ $key ] ?? null;

		return is_numeric( $value ) ? (int) $value : $fallback;
	}
}
