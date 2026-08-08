<?php
/**
 * Send a webhook.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Actions;

use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Workflow\ActionHandlerInterface;
use Hiveclerk\Domain\Workflow\ActionResult;
use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\WorkflowContext;
use Hiveclerk\Modules\Integrations\Services\WebhookDispatcher;

/**
 * Posts through the Integrations dispatcher, never to a URL of its own.
 *
 * ## Why this node has no URL field
 *
 * The obvious design gives each webhook node its own endpoint, and it is
 * a hole. A URL typed into a workflow is a URL the server will fetch, and
 * `169.254.169.254` returns cloud instance credentials to anything that
 * asks. The Integrations module already resolves and rejects private and
 * link-local ranges, signs the payload and retries with a policy — so
 * this action names an event and the endpoints that subscribed to it
 * receive the call. Adding a URL box here would have meant duplicating
 * all of that, or shipping without it.
 *
 * Works on conversation runs as well as lead runs: the payload is
 * whatever the context holds, and a handoff request with no lead
 * attached is exactly the case a support integration wants to hear about.
 */
final class WebhookAction implements ActionHandlerInterface {

	/**
	 * The event prefix every workflow webhook carries.
	 *
	 * Namespaced so a subscriber can route on it, and so a workflow
	 * cannot impersonate one of the product's own events — `lead.captured`
	 * from a workflow would be indistinguishable downstream from a real
	 * capture, which is a lie the receiving system has no way to catch.
	 */
	public const EVENT_PREFIX = 'workflow.';

	/**
	 * Construct.
	 *
	 * @param WebhookDispatcher       $webhooks Dispatcher.
	 * @param LeadRepositoryInterface $leads    Lead lookup, for the payload.
	 */
	public function __construct(
		private readonly WebhookDispatcher $webhooks,
		private readonly LeadRepositoryInterface $leads
	) {
	}

	/**
	 * Which action this handles.
	 *
	 * @return ActionType
	 */
	public function type(): ActionType {
		return ActionType::Webhook;
	}

	/**
	 * Dispatch.
	 *
	 * @param WorkflowContext      $context What the run knows.
	 * @param array<string, mixed> $config  Node configuration.
	 * @return ActionResult
	 */
	public function execute( WorkflowContext $context, array $config ): ActionResult {
		$event = $this->event( $config );

		try {
			$delivered = $this->webhooks->dispatch( $event, $this->payload( $context ) );
		} catch ( \Throwable $e ) {
			return ActionResult::failed( $e->getMessage() );
		}

		if ( ! $delivered ) {
			// Nothing subscribed. Not a failure: a site that has not set
			// up a webhook endpoint has simply not set one up, and failing
			// the run would make the workflow look broken to somebody who
			// added the node speculatively.
			return ActionResult::skipped(
				sprintf(
					/* translators: %s: event name. */
					__( 'Nothing is subscribed to %s.', 'hiveclerk' ),
					$event
				)
			);
		}

		return ActionResult::done(
			sprintf(
				/* translators: %s: event name. */
				__( 'Sent %s.', 'hiveclerk' ),
				$event
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
		$name = $config['event'] ?? null;

		if ( ! is_string( $name ) || '' === trim( $name ) ) {
			return __( 'Name the event your endpoint should listen for.', 'hiveclerk' );
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

		return sprintf(
			/* translators: %s: event name. */
			__( 'Send the webhook event %s', 'hiveclerk' ),
			$this->event( $config )
		);
	}

	/**
	 * The namespaced event name.
	 *
	 * @param array<string, mixed> $config Node configuration.
	 * @return string
	 */
	private function event( array $config ): string {
		$name = $config['event'] ?? null;
		$slug = is_string( $name ) ? sanitize_key( $name ) : '';

		return self::EVENT_PREFIX . ( '' === $slug ? 'triggered' : $slug );
	}

	/**
	 * What goes on the wire.
	 *
	 * The lead is serialised by the same mapper the CRM connectors use
	 * where one exists; here the context is already flat and already
	 * carries only fields the operator can see on screen, so it is sent
	 * as-is. Nothing secret has ever been put in a context.
	 *
	 * @param WorkflowContext $context What the run knows.
	 * @return array<string, mixed>
	 */
	private function payload( WorkflowContext $context ): array {
		$payload = array(
			'workflow' => $context->string( 'workflow.name' ),
			'run_id'   => $context->int( 'run.id' ),
			'subject'  => $context->string( 'subject' ),
			'lead'     => null,
		);

		$leadId = $context->int( 'lead.id' );

		if ( null !== $leadId ) {
			$lead = $this->leads->find( $leadId );

			if ( null !== $lead ) {
				$payload['lead'] = array(
					'uuid'    => $lead->uuid->value,
					'name'    => $lead->displayName(),
					'email'   => $lead->email,
					'phone'   => $lead->phone,
					'company' => $lead->company,
					'score'   => $lead->score,
					'band'    => $lead->band->value,
					'status'  => $lead->status->value,
					'source'  => $lead->source,
				);
			}
		}

		$conversationUuid = $context->string( 'conversation.uuid' );

		if ( null !== $conversationUuid ) {
			$payload['conversation'] = array( 'uuid' => $conversationUuid );
		}

		return $payload;
	}
}
