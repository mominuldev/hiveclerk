<?php
/**
 * Sequence endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Email\Http;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Licence\Feature;
use Hiveclerk\Core\Licence\LicenceGate;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Email\EmailLogEntry;
use Hiveclerk\Domain\Email\EmailLogRepositoryInterface;
use Hiveclerk\Domain\Email\EmailSequence;
use Hiveclerk\Domain\Email\ExitCondition;
use Hiveclerk\Domain\Email\SendStatus;
use Hiveclerk\Domain\Email\SequenceRepositoryInterface;
use Hiveclerk\Domain\Email\SequenceStep;
use Hiveclerk\Domain\Email\SequenceStepRepositoryInterface;
use Hiveclerk\Domain\Email\TriggerType;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Email\Services\CopyGenerator;
use Hiveclerk\Modules\Email\Services\EmailRenderer;
use Hiveclerk\Modules\Email\Services\MergeTags;
use Hiveclerk\Modules\Email\Services\SequenceService;
use Hiveclerk\Modules\Email\Services\SuppressionList;
use Hiveclerk\Modules\Email\Support\EmailException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The Email surface (D9 §3.6).
 *
 * Sequences are addressed by UUID and steps by their storage id. That
 * split follows the rest of the product: a UUID exists so a record
 * exposed over HTTP cannot be enumerated by counting upwards, and a step
 * is only ever reached through its sequence by somebody who already holds
 * `manage_leads`.
 */
final class SequenceController extends AbstractController {

	/**
	 * Construct.
	 *
	 * @param SequenceService                 $service   Sequence management.
	 * @param SequenceRepositoryInterface     $sequences Sequence storage.
	 * @param SequenceStepRepositoryInterface $steps     Step storage.
	 * @param EmailLogRepositoryInterface     $log       Send log.
	 * @param LeadRepositoryInterface         $leads     Lead lookup, for previews.
	 * @param AgentRepositoryInterface        $agents    Clerks, for the drafting model.
	 * @param CopyGenerator                   $copy      AI drafting.
	 * @param EmailRenderer                   $renderer  Message rendering.
	 * @param MergeTags                       $tags      Merge tag vocabulary.
	 * @param SuppressionList                 $suppression Do-not-email list.
	 * @param LicenceGate                     $licence   Tier entitlements.
	 */
	public function __construct(
		private readonly SequenceService $service,
		private readonly SequenceRepositoryInterface $sequences,
		private readonly SequenceStepRepositoryInterface $steps,
		private readonly EmailLogRepositoryInterface $log,
		private readonly LeadRepositoryInterface $leads,
		private readonly AgentRepositoryInterface $agents,
		private readonly CopyGenerator $copy,
		private readonly EmailRenderer $renderer,
		private readonly MergeTags $tags,
		private readonly SuppressionList $suppression,
		private readonly LicenceGate $licence
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		$manage = $this->requires( Capabilities::MANAGE_LEADS );

		register_rest_route(
			self::NAMESPACE,
			'/admin/email/sequences',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $manage,
					'args'                => $this->collectionArgs(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $manage,
					'args'                => $this->sequenceArgs(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/email/sequences/(?P<uuid>[a-f0-9-]{36})',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $manage,
					'args'                => $this->sequenceArgs(),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $manage,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/email/sequences/(?P<uuid>[a-f0-9-]{36})/activate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'activate' ),
				'permission_callback' => $manage,
			)
		);

		// Not in D9 §3.6, and necessary: without it the paused state that
		// SequenceStatus models is unreachable, and the only way to stop a
		// live sequence would be to delete it and lose everybody in it.
		register_rest_route(
			self::NAMESPACE,
			'/admin/email/sequences/(?P<uuid>[a-f0-9-]{36})/pause',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'pause' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/email/sequences/(?P<uuid>[a-f0-9-]{36})/steps',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'steps' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'createStep' ),
					'permission_callback' => $manage,
					'args'                => $this->stepArgs(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/email/steps/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'updateStep' ),
					'permission_callback' => $manage,
					'args'                => $this->stepArgs(),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'deleteStep' ),
					'permission_callback' => $manage,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/email/steps/(?P<id>\d+)/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => $manage,
				'args'                => array(
					'goal'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'agent' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'lead'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/email/steps/(?P<id>\d+)/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/email/steps/(?P<id>\d+)/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => $manage,
				'args'                => array(
					'lead' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/email/log',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'sendLog' ),
				'permission_callback' => $manage,
				'args'                => array_merge(
					$this->collectionArgs(),
					array(
						'status' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
					)
				),
			)
		);
	}

	/**
	 * Every sequence.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->pagination( $request );

		$sequences = array_map(
			fn ( EmailSequence $sequence ): array => $this->present( $sequence ),
			$this->sequences->paginate( $pagination )
		);

		return ApiResponse::ok(
			array(
				'sequences'  => $sequences,
				'triggers'   => $this->triggerVocabulary(),
				'exits'      => $this->exitVocabulary(),
				'merge_tags' => $this->tags->vocabulary(),
				'suppressed' => $this->suppression->count(),
				'max_steps'  => SequenceService::MAX_STEPS,
			),
			array(
				'pagination' => array(
					'page'        => $pagination->page,
					'per_page'    => $pagination->perPage,
					'total'       => $this->sequences->countAll(),
					'total_pages' => $pagination->totalPages( $this->sequences->countAll() ),
				),
			)
		);
	}

	/**
	 * One sequence with its steps.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$sequence = $this->sequence( $request );

		return $sequence instanceof WP_Error
			? $sequence
			: ApiResponse::ok( $this->presentDetail( $sequence ) );
	}

	/**
	 * Create a sequence.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// FR-EML-08. Creating and activating are both gated; editing and
		// reading are not. A customer whose licence lapses keeps every
		// sequence they wrote and can still see what it sent — they just
		// cannot start another one. Deleting their copy would be taking
		// back work they did.
		$refusal = $this->licence->refusal( Feature::EmailSequences );

		if ( null !== $refusal ) {
			return $refusal;
		}

		try {
			$sequence = $this->service->create( $this->sequenceInput( $request ) );
		} catch ( EmailException $e ) {
			return ApiResponse::error( $e->errorCode, $e->getMessage(), $e->status );
		}

		return ApiResponse::ok( $this->presentDetail( $sequence ), array(), 201 );
	}

	/**
	 * Change a sequence.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$sequence = $this->sequence( $request );

		if ( $sequence instanceof WP_Error ) {
			return $sequence;
		}

		return ApiResponse::ok(
			$this->presentDetail( $this->service->update( $sequence, $this->sequenceInput( $request ) ) )
		);
	}

	/**
	 * Delete a sequence.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$sequence = $this->sequence( $request );

		if ( $sequence instanceof WP_Error ) {
			return $sequence;
		}

		$this->service->delete( $sequence );

		return ApiResponse::ok( array( 'deleted' => true ) );
	}

	/**
	 * Put a sequence live.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function activate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$refusal = $this->licence->refusal( Feature::EmailSequences );

		if ( null !== $refusal ) {
			return $refusal;
		}

		$sequence = $this->sequence( $request );

		if ( $sequence instanceof WP_Error ) {
			return $sequence;
		}

		try {
			$sequence = $this->service->activate( $sequence );
		} catch ( EmailException $e ) {
			return ApiResponse::error( $e->errorCode, $e->getMessage(), $e->status );
		}

		return ApiResponse::ok( $this->presentDetail( $sequence ) );
	}

	/**
	 * Stop a sequence sending.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pause( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$sequence = $this->sequence( $request );

		return $sequence instanceof WP_Error
			? $sequence
			: ApiResponse::ok( $this->presentDetail( $this->service->pause( $sequence ) ) );
	}

	/**
	 * A sequence's steps.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function steps( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$sequence = $this->sequence( $request );

		if ( $sequence instanceof WP_Error ) {
			return $sequence;
		}

		return ApiResponse::ok( $this->presentSteps( $sequence ) );
	}

	/**
	 * Add a step.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function createStep( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$sequence = $this->sequence( $request );

		if ( $sequence instanceof WP_Error ) {
			return $sequence;
		}

		try {
			$step = $this->service->saveStep( $sequence, null, $this->stepInput( $request ) );
		} catch ( EmailException $e ) {
			return ApiResponse::error( $e->errorCode, $e->getMessage(), $e->status );
		}

		return ApiResponse::ok( self::presentStep( $step ), array(), 201 );
	}

	/**
	 * Change a step.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function updateStep( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$step = $this->step( $request );

		if ( $step instanceof WP_Error ) {
			return $step;
		}

		$sequence = $this->sequences->find( $step->sequenceId );

		if ( null === $sequence ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, __( 'That sequence no longer exists.', 'hiveclerk' ), 404 );
		}

		try {
			$step = $this->service->saveStep( $sequence, $step, $this->stepInput( $request ) );
		} catch ( EmailException $e ) {
			return ApiResponse::error( $e->errorCode, $e->getMessage(), $e->status );
		}

		return ApiResponse::ok( self::presentStep( $step ) );
	}

	/**
	 * Remove a step.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function deleteStep( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$step = $this->step( $request );

		if ( $step instanceof WP_Error ) {
			return $step;
		}

		$sequence = $this->sequences->find( $step->sequenceId );

		if ( null === $sequence ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, __( 'That sequence no longer exists.', 'hiveclerk' ), 404 );
		}

		$this->service->deleteStep( $sequence, $step );

		return ApiResponse::ok( $this->presentSteps( $sequence ) );
	}

	/**
	 * Ask a model to draft this step.
	 *
	 * The draft is returned, not saved. FR-EML-03's gate begins here: the
	 * operator sees words before anything is stored, and storing them
	 * marks the step AI-generated so it still cannot send unapproved.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$step = $this->step( $request );

		if ( $step instanceof WP_Error ) {
			return $step;
		}

		$agent = $this->draftingAgent( $this->stringParam( $request, 'agent' ) );

		if ( null === $agent ) {
			return ApiResponse::error(
				ErrorCode::PROVIDER_UNCONFIGURED,
				__( 'Drafting needs a published clerk with a provider and model set. Publish one first.', 'hiveclerk' ),
				409
			);
		}

		$lead = $this->leadParam( $request );

		$draft = $this->copy->draft(
			$agent,
			(string) $request->get_param( 'goal' ),
			$lead,
			null,
			$step->position
		);

		if ( null === $draft ) {
			return ApiResponse::error(
				ErrorCode::PROVIDER_ERROR,
				__( 'The model did not return a usable draft. Try again, or write the email yourself.', 'hiveclerk' ),
				502
			);
		}

		return ApiResponse::ok( $draft->toArray() );
	}

	/**
	 * Sign off an AI-drafted step.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$step = $this->step( $request );

		if ( $step instanceof WP_Error ) {
			return $step;
		}

		try {
			$step = $this->service->approveStep( $step, get_current_user_id() );
		} catch ( EmailException $e ) {
			return ApiResponse::error( $e->errorCode, $e->getMessage(), $e->status );
		}

		return ApiResponse::ok( self::presentStep( $step ) );
	}

	/**
	 * Render a step as it would arrive.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$step = $this->step( $request );

		if ( $step instanceof WP_Error ) {
			return $step;
		}

		$sequence = $this->sequences->find( $step->sequenceId );

		if ( null === $sequence ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, __( 'That sequence no longer exists.', 'hiveclerk' ), 404 );
		}

		$lead = $this->leadParam( $request ) ?? $this->sampleLead();

		$message = $this->renderer->render( $step, $sequence, $lead );

		if ( null === $message ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				__( 'That lead has no email address to preview against.', 'hiveclerk' ),
				422
			);
		}

		return ApiResponse::ok(
			array(
				'subject' => $message->subject,
				'html'    => $message->html,
				'text'    => $message->text,
				'to'      => $message->to,
				'sample'  => null === $this->leadParam( $request ),
			)
		);
	}

	/**
	 * A page of the send log.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function sendLog( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->pagination( $request );
		$status     = SendStatus::tryFrom( (string) $this->stringParam( $request, 'status' ) );

		$entries = array_map(
			static fn ( EmailLogEntry $entry ): array => array(
				'id'           => $entry->id,
				'lead_id'      => $entry->leadId,
				// The address is shown because the operator reading this
				// screen already has the lead record open beside it.
				'to'           => $entry->toEmail,
				'subject'      => $entry->subject,
				'status'       => $entry->status->value,
				'status_label' => $entry->status->label(),
				'error'        => $entry->error,
				'step_id'      => $entry->stepId,
				'sent_at'      => $entry->sentAt?->format( 'c' ),
				'created_at'   => $entry->createdAt?->format( 'c' ),
			),
			$this->log->paginate( $pagination, null, $status )
		);

		return ApiResponse::collection(
			$entries,
			$pagination,
			$this->log->countMatching( null, $status )
		);
	}

	/**
	 * One sequence, for a list row.
	 *
	 * @param EmailSequence $sequence Sequence.
	 * @return array<string, mixed>
	 */
	private function present( EmailSequence $sequence ): array {
		return array(
			'uuid'            => $sequence->uuid->value,
			'name'            => $sequence->name,
			'status'          => $sequence->status->value,
			'status_label'    => $sequence->status->label(),
			'trigger'         => $sequence->trigger->value,
			'trigger_label'   => $sequence->trigger->label(),
			'threshold'       => $sequence->threshold(),
			'stage_id'        => $sequence->triggerStageId(),
			'abandon_after'   => $sequence->abandonAfterMinutes(),
			'from_name'       => $sequence->fromName,
			'from_email'      => $sequence->fromEmail,
			'reply_to'        => $sequence->replyTo,
			'enrolled'        => $sequence->enrolledCount,
			'steps'           => null === $sequence->id ? 0 : $this->steps->countFor( $sequence->id ),
			'exit_conditions' => $sequence->exitConditions,
			'stats'           => $this->service->stats( $sequence ),
			'created_at'      => $sequence->createdAt?->format( 'c' ),
		);
	}

	/**
	 * One sequence with its steps.
	 *
	 * @param EmailSequence $sequence Sequence.
	 * @return array<string, mixed>
	 */
	private function presentDetail( EmailSequence $sequence ): array {
		return array_merge(
			$this->present( $sequence ),
			$this->presentSteps( $sequence )
		);
	}

	/**
	 * A sequence's steps, and whether it could go live.
	 *
	 * @param EmailSequence $sequence Sequence.
	 * @return array<string, mixed>
	 */
	private function presentSteps( EmailSequence $sequence ): array {
		$steps = null === $sequence->id ? array() : $this->steps->forSequence( $sequence->id );

		$blockers = array();

		foreach ( $steps as $index => $step ) {
			$blocker = $step->blocker();

			if ( null !== $blocker ) {
				$blockers[] = array(
					'position' => $index,
					'reason'   => $blocker,
				);
			}
		}

		return array(
			'steps'        => array_map(
				static fn ( SequenceStep $step ): array => self::presentStep( $step ),
				$steps
			),
			'blockers'     => $blockers,
			'can_activate' => array() !== $steps && array() === $blockers,
		);
	}

	/**
	 * One step.
	 *
	 * @param SequenceStep $step Step.
	 * @return array<string, mixed>
	 */
	public static function presentStep( SequenceStep $step ): array {
		return array(
			'id'            => $step->id,
			'position'      => $step->position,
			'delay_minutes' => $step->delayMinutes,
			'subject'       => $step->subject,
			'body_html'     => $step->bodyHtml,
			'body_text'     => $step->bodyText,
			'ai_generated'  => $step->aiGenerated,
			'approved_by'   => $step->approvedBy,
			'approved_at'   => $step->approvedAt?->format( 'c' ),
			'sendable'      => $step->isSendable(),
			'blocker'       => $step->blocker(),
		);
	}

	/**
	 * The trigger list the builder's dropdown is built from.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function triggerVocabulary(): array {
		$triggers = array();

		foreach ( TriggerType::cases() as $trigger ) {
			$triggers[] = array(
				'value'           => $trigger->value,
				'label'           => $trigger->label(),
				'needs_threshold' => $trigger->needsThreshold(),
			);
		}

		return $triggers;
	}

	/**
	 * The exit-condition list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function exitVocabulary(): array {
		$exits = array();

		foreach ( ExitCondition::cases() as $exit ) {
			$exits[] = array(
				'value'       => $exit->value,
				'label'       => $exit->label(),
				'needs_value' => $exit->needsValue(),
			);
		}

		return $exits;
	}

	/**
	 * Resolve the sequence named in the route.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return EmailSequence|WP_Error
	 */
	private function sequence( WP_REST_Request $request ): EmailSequence|WP_Error {
		$raw = (string) $request->get_param( 'uuid' );

		$sequence = Uuid::isValid( $raw ) ? $this->sequences->findByUuid( new Uuid( $raw ) ) : null;

		if ( null === $sequence || null !== $sequence->deletedAt ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, __( 'That sequence does not exist.', 'hiveclerk' ), 404 );
		}

		return $sequence;
	}

	/**
	 * Resolve the step named in the route.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return SequenceStep|WP_Error
	 */
	private function step( WP_REST_Request $request ): SequenceStep|WP_Error {
		$step = $this->steps->find( (int) $request->get_param( 'id' ) );

		if ( null === $step ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, __( 'That email does not exist.', 'hiveclerk' ), 404 );
		}

		return $step;
	}

	/**
	 * The clerk whose provider and model draft the copy.
	 *
	 * @param string|null $uuid Requested clerk.
	 * @return \Hiveclerk\Domain\Agent\Agent|null
	 */
	private function draftingAgent( ?string $uuid ): ?\Hiveclerk\Domain\Agent\Agent {
		if ( null !== $uuid && Uuid::isValid( $uuid ) ) {
			$agent = $this->agents->findByUuid( new Uuid( $uuid ) );

			if ( null !== $agent && null !== $agent->provider() && null !== $agent->model() ) {
				return $agent;
			}
		}

		foreach ( $this->agents->published() as $agent ) {
			if ( null !== $agent->provider() && null !== $agent->model() ) {
				return $agent;
			}
		}

		return null;
	}

	/**
	 * The lead named in the request, when one was.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return Lead|null
	 */
	private function leadParam( WP_REST_Request $request ): ?Lead {
		$uuid = $this->stringParam( $request, 'lead' );

		if ( null === $uuid || ! Uuid::isValid( $uuid ) ) {
			return null;
		}

		return $this->leads->findByUuid( new Uuid( $uuid ) );
	}

	/**
	 * A stand-in lead for previewing.
	 *
	 * Invented rather than taken from the database. A preview built from
	 * the most recent real lead would put a named individual's details on
	 * screen every time somebody opened the editor, which is a use of
	 * their data nobody consented to and a surprise for the operator.
	 *
	 * @return Lead
	 */
	private function sampleLead(): Lead {
		return new Lead(
			id: null,
			uuid: Uuid::generate(),
			email: 'sample@example.com',
			firstName: 'Sam',
			lastName: 'Taylor',
			company: 'Example Ltd',
			jobTitle: 'Operations Manager',
		);
	}

	/**
	 * Read the writable sequence fields.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<string, mixed>
	 */
	private function sequenceInput( WP_REST_Request $request ): array {
		$input = array();

		foreach ( array( 'name', 'trigger', 'from_name', 'from_email', 'reply_to' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = (string) $request->get_param( $key );
			}
		}

		foreach ( array( 'threshold', 'stage_id', 'abandon_after' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = (int) $request->get_param( $key );
			}
		}

		if ( null !== $request->get_param( 'exit_conditions' ) ) {
			$input['exit_conditions'] = $request->get_param( 'exit_conditions' );
		}

		return $input;
	}

	/**
	 * Read the writable step fields.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<string, mixed>
	 */
	private function stepInput( WP_REST_Request $request ): array {
		$input = array();

		if ( null !== $request->get_param( 'subject' ) ) {
			$input['subject'] = sanitize_text_field( (string) $request->get_param( 'subject' ) );
		}

		if ( null !== $request->get_param( 'body_html' ) ) {
			// Stored as written, filtered at send. Filtering here would
			// silently delete a table the operator had a reason for, and
			// they would find out from a recipient weeks later.
			$input['body_html'] = wp_kses_post( (string) $request->get_param( 'body_html' ) );
		}

		if ( null !== $request->get_param( 'body_text' ) ) {
			$input['body_text'] = sanitize_textarea_field( (string) $request->get_param( 'body_text' ) );
		}

		if ( null !== $request->get_param( 'delay_minutes' ) ) {
			$input['delay_minutes'] = (int) $request->get_param( 'delay_minutes' );
		}

		if ( null !== $request->get_param( 'ai_generated' ) ) {
			$input['ai_generated'] = (bool) $request->get_param( 'ai_generated' );
		}

		return $input;
	}

	/**
	 * Arguments accepted when writing a sequence.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function sequenceArgs(): array {
		return array(
			'name'            => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'trigger'         => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			),
			'threshold'       => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'stage_id'        => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'abandon_after'   => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'from_name'       => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'from_email'      => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_email',
			),
			'reply_to'        => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_email',
			),
			'exit_conditions' => array(
				'type'     => 'array',
				'required' => false,
			),
		);
	}

	/**
	 * Arguments accepted when writing a step.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function stepArgs(): array {
		return array(
			'subject'       => array(
				'type'     => 'string',
				'required' => false,
			),
			'body_html'     => array(
				'type'     => 'string',
				'required' => false,
			),
			'body_text'     => array(
				'type'     => 'string',
				'required' => false,
			),
			'delay_minutes' => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'ai_generated'  => array(
				'type'     => 'boolean',
				'required' => false,
			),
		);
	}
}
