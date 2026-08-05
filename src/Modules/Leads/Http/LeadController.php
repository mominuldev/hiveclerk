<?php
/**
 * Lead endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Http;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Conversation\Conversation;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Lead\Activity;
use Hiveclerk\Domain\Lead\Lead;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStage;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStatus;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Leads\Services\LeadExporter;
use Hiveclerk\Modules\Leads\Services\LeadService;
use Hiveclerk\Modules\Leads\Services\ScoringService;
use Hiveclerk\Modules\Leads\Support\LeadException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The pipeline, the lead detail and everything done to one (D9 §3.5).
 *
 * Every route is gated on `manage_leads`, which `shop_manager` has and
 * `editor` does not — a lead record holds a named person's email address
 * and telephone number, and that is a narrower thing than the
 * conversations an editor may read.
 */
final class LeadController extends AbstractController {

	/**
	 * Timeline rows one detail view returns.
	 */
	private const TIMELINE_LIMIT = 100;

	/**
	 * Construct.
	 *
	 * @param LeadService                     $leads         Lead lifecycle.
	 * @param LeadRepositoryInterface         $repository    Lead storage.
	 * @param LeadStageRepositoryInterface    $stages        Stage storage.
	 * @param ScoringService                  $scoring       Scoring.
	 * @param LeadExporter                    $exporter      CSV export.
	 * @param ConversationRepositoryInterface $conversations Conversation storage.
	 * @param AgentRepositoryInterface        $agents        Clerk storage.
	 */
	public function __construct(
		private readonly LeadService $leads,
		private readonly LeadRepositoryInterface $repository,
		private readonly LeadStageRepositoryInterface $stages,
		private readonly ScoringService $scoring,
		private readonly LeadExporter $exporter,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly AgentRepositoryInterface $agents
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		$manage = $this->requires( Capabilities::MANAGE_LEADS );
		$uuid   = '(?P<uuid>[a-f0-9-]{36})';

		register_rest_route(
			self::NAMESPACE,
			'/admin/leads',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $manage,
					'args'                => $this->listArgs(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $manage,
					'args'                => $this->writeArgs(),
				),
			)
		);

		// Registered before the uuid route so "export" and "merge" are not
		// read as identifiers. WordPress matches in registration order.
		register_rest_route(
			self::NAMESPACE,
			'/admin/leads/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $manage,
				'args'                => $this->listArgs(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/leads/merge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'merge' ),
				'permission_callback' => $manage,
				'args'                => array(
					'winner' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'loser'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/leads/' . $uuid,
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
					'args'                => $this->writeArgs(),
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
			'/admin/leads/' . $uuid . '/timeline',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'timeline' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/leads/' . $uuid . '/score',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'score' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/leads/' . $uuid . '/score/adjust',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'adjust' ),
				'permission_callback' => $manage,
				'args'                => array(
					'points' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'reason' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/leads/' . $uuid . '/stage',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'stage' ),
				'permission_callback' => $manage,
				'args'                => array(
					'stage_id' => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/leads/' . $uuid . '/notes',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'note' ),
				'permission_callback' => $manage,
				'args'                => array(
					'body' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * The pipeline, or the table.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->pagination( $request );
		$filters    = $this->filters( $request );
		$order      = $this->stringParam( $request, 'order_by' ) ?? 'created_at';
		$direction  = 'asc' === strtolower( (string) $this->stringParam( $request, 'order' ) ) ? 'ASC' : 'DESC';

		$leads  = $this->repository->paginate( $pagination, $filters, $order, $direction );
		$stages = $this->stageMap();

		return ApiResponse::ok(
			array(
				'leads'  => array_map(
					fn ( Lead $lead ): array => $this->present( $lead, $stages ),
					$leads
				),
				'stages' => array_map(
					static fn ( LeadStage $stage ): array => self::presentStage( $stage ),
					array_values( $stages )
				),
				// Counts for every column, not just the page. A board header
				// showing the number on this page would say "New 25" on a
				// column holding four hundred.
				'counts' => $this->repository->countsByStage( $filters ),
			),
			array(
				'pagination' => array(
					'page'        => $pagination->page,
					'per_page'    => $pagination->perPage,
					'total'       => $this->repository->count( $filters ),
					'total_pages' => $pagination->totalPages( $this->repository->count( $filters ) ),
				),
			)
		);
	}

	/**
	 * One lead, in full (FR-LED-06).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$lead = $this->resolve( $request );

		if ( $lead instanceof WP_Error ) {
			return $lead;
		}

		return ApiResponse::ok( $this->detail( $lead ) );
	}

	/**
	 * Add a lead by hand.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$lead = $this->leads->create( $this->input( $request ), get_current_user_id() );
		} catch ( LeadException $e ) {
			return ApiResponse::error( $e->errorCode, $e->getMessage(), $e->status );
		}

		return ApiResponse::ok( $this->detail( $lead ), array(), 201 );
	}

	/**
	 * Change a lead.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$lead = $this->resolve( $request );

		if ( $lead instanceof WP_Error ) {
			return $lead;
		}

		try {
			$lead = $this->leads->update( $lead, $this->input( $request ), get_current_user_id() );
		} catch ( LeadException $e ) {
			return ApiResponse::error( $e->errorCode, $e->getMessage(), $e->status );
		}

		return ApiResponse::ok( $this->detail( $lead ) );
	}

	/**
	 * Delete a lead.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$lead = $this->resolve( $request );

		if ( $lead instanceof WP_Error ) {
			return $lead;
		}

		return ApiResponse::ok( array( 'deleted' => $this->leads->delete( $lead ) ) );
	}

	/**
	 * The lead's timeline.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function timeline( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$lead = $this->resolve( $request );

		if ( $lead instanceof WP_Error ) {
			return $lead;
		}

		return ApiResponse::ok(
			array_map(
				static fn ( Activity $activity ): array => self::presentActivity( $activity ),
				$this->leads->timeline( $lead, self::TIMELINE_LIMIT )
			)
		);
	}

	/**
	 * The score breakdown (FR-LED-04).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function score( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$lead = $this->resolve( $request );

		if ( $lead instanceof WP_Error ) {
			return $lead;
		}

		return ApiResponse::ok( $this->scoring->breakdown( $lead ) );
	}

	/**
	 * Adjust a score by hand.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function adjust( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$lead = $this->resolve( $request );

		if ( $lead instanceof WP_Error ) {
			return $lead;
		}

		$points = (int) $request->get_param( 'points' );

		if ( 0 === $points ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				__( 'An adjustment of zero would add a line to the breakdown and change nothing.', 'hiveclerk' ),
				422
			);
		}

		$this->scoring->applyManualAdjustment(
			$lead,
			$points,
			$this->stringParam( $request, 'reason' ),
			get_current_user_id()
		);

		return ApiResponse::ok( $this->scoring->breakdown( $lead ) );
	}

	/**
	 * Move a lead between columns (FR-LED-05).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function stage( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$lead = $this->resolve( $request );

		if ( $lead instanceof WP_Error ) {
			return $lead;
		}

		$stageId = $request->get_param( 'stage_id' );

		try {
			$lead = $this->leads->moveToStage(
				$lead,
				is_numeric( $stageId ) && (int) $stageId > 0 ? (int) $stageId : null,
				get_current_user_id()
			);
		} catch ( LeadException $e ) {
			return ApiResponse::error( $e->errorCode, $e->getMessage(), $e->status );
		}

		return ApiResponse::ok( $this->detail( $lead ) );
	}

	/**
	 * Merge two leads (FR-LED-08).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function merge( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$winner = $this->byUuid( (string) $request->get_param( 'winner' ) );
		$loser  = $this->byUuid( (string) $request->get_param( 'loser' ) );

		if ( null === $winner || null === $loser ) {
			return ApiResponse::error(
				ErrorCode::NOT_FOUND,
				__( 'One of those leads does not exist.', 'hiveclerk' ),
				404
			);
		}

		try {
			$merged = $this->leads->merge( $winner, $loser, get_current_user_id() );
		} catch ( LeadException $e ) {
			return ApiResponse::error( $e->errorCode, $e->getMessage(), $e->status );
		}

		return ApiResponse::ok( $this->detail( $merged ) );
	}

	/**
	 * Add an internal note to the timeline.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function note( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$lead = $this->resolve( $request );

		if ( $lead instanceof WP_Error ) {
			return $lead;
		}

		$body = $this->stringParam( $request, 'body' );

		if ( null === $body ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				__( 'Write something before saving the note.', 'hiveclerk' ),
				422
			);
		}

		$user = wp_get_current_user();

		$activity = $this->leads->note(
			$lead,
			\Hiveclerk\Domain\Lead\ActivityType::NoteAdded,
			sprintf(
				/* translators: %s: display name of the staff member. */
				__( 'Note from %s', 'hiveclerk' ),
				$user->display_name
			),
			$body,
			get_current_user_id()
		);

		return ApiResponse::ok( self::presentActivity( $activity ), array(), 201 );
	}

	/**
	 * Leads as CSV (FR-LED-10).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function export( WP_REST_Request $request ): WP_REST_Response {
		$configs = array();

		foreach ( $this->agents->paginate( new Pagination( 1, Pagination::MAX_PER_PAGE ) ) as $agent ) {
			if ( $agent instanceof Agent ) {
				$configs[] = $agent->leadConfig;
			}
		}

		return ApiResponse::ok(
			$this->exporter->export(
				$this->filters( $request ),
				LeadExporter::questionKeys( $configs )
			)
		);
	}

	/**
	 * Resolve the lead named in the route.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return Lead|WP_Error
	 */
	private function resolve( WP_REST_Request $request ): Lead|WP_Error {
		$lead = $this->byUuid( (string) $request->get_param( 'uuid' ) );

		if ( null === $lead ) {
			return ApiResponse::error(
				ErrorCode::NOT_FOUND,
				__( 'That lead does not exist.', 'hiveclerk' ),
				404
			);
		}

		return $lead;
	}

	/**
	 * A lead by its public identifier.
	 *
	 * @param string $uuid Public identifier.
	 * @return Lead|null
	 */
	private function byUuid( string $uuid ): ?Lead {
		return Uuid::isValid( $uuid ) ? $this->leads->findByUuid( new Uuid( $uuid ) ) : null;
	}

	/**
	 * A lead as a pipeline card.
	 *
	 * @param Lead                   $lead   The lead.
	 * @param array<int, LeadStage>  $stages Stages by id.
	 * @return array<string, mixed>
	 */
	private function present( Lead $lead, array $stages ): array {
		$stage = null === $lead->stageId ? null : ( $stages[ $lead->stageId ] ?? null );

		return array(
			'uuid'           => $lead->uuid->value,
			'name'           => $lead->displayName(),
			'first_name'     => $lead->firstName,
			'last_name'      => $lead->lastName,
			'email'          => $lead->email,
			'phone'          => $lead->phone,
			'company'        => $lead->company,
			'job_title'      => $lead->jobTitle,
			'website'        => $lead->website,
			'score'          => $lead->score,
			'band'           => $lead->band->value,
			'band_label'     => $lead->band->label(),
			'status'         => $lead->status->value,
			'status_label'   => $lead->status->label(),
			'stage_id'       => $lead->stageId,
			'stage'          => null === $stage ? null : $stage->name,
			'source'         => $lead->source,
			'owner_user_id'  => $lead->ownerUserId,
			'custom_fields'  => $lead->customFields,
			'first_seen_at'  => $lead->firstSeenAt?->format( 'Y-m-d H:i:s' ),
			'last_active_at' => $lead->lastActiveAt?->format( 'Y-m-d H:i:s' ),
			'created_at'     => $lead->createdAt?->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * A lead with everything the detail screen reads.
	 *
	 * @param Lead $lead The lead.
	 * @return array<string, mixed>
	 */
	private function detail( Lead $lead ): array {
		$conversations = null === $lead->id ? array() : $this->conversations->forLead( $lead->id );

		return array_merge(
			$this->present( $lead, $this->stageMap() ),
			$this->scoring->breakdown( $lead ),
			array(
				'consent'       => $lead->consent,
				'conversations' => array_map(
					static fn ( Conversation $conversation ): array => array(
						'uuid'          => $conversation->uuid->value,
						'started_at'    => $conversation->startedAt?->format( 'Y-m-d H:i:s' ),
						'message_count' => $conversation->messageCount,
						'page_url'      => $conversation->pageUrl,
						'status'        => $conversation->status->value,
					),
					$conversations
				),
				'timeline'      => array_map(
					static fn ( Activity $activity ): array => self::presentActivity( $activity ),
					$this->leads->timeline( $lead, self::TIMELINE_LIMIT )
				),
			)
		);
	}

	/**
	 * Every stage, keyed by id.
	 *
	 * @return array<int, LeadStage>
	 */
	private function stageMap(): array {
		$map = array();

		foreach ( $this->stages->all() as $stage ) {
			if ( null !== $stage->id ) {
				$map[ $stage->id ] = $stage;
			}
		}

		return $map;
	}

	/**
	 * A stage for the board.
	 *
	 * @param LeadStage $stage The stage.
	 * @return array<string, mixed>
	 */
	public static function presentStage( LeadStage $stage ): array {
		return array(
			'id'       => $stage->id,
			'name'     => $stage->name,
			'slug'     => $stage->slug,
			'color'    => $stage->color,
			'position' => $stage->position,
			'is_won'   => $stage->isWon,
			'is_lost'  => $stage->isLost,
		);
	}

	/**
	 * A timeline row.
	 *
	 * @param Activity $activity The activity.
	 * @return array<string, mixed>
	 */
	public static function presentActivity( Activity $activity ): array {
		return array(
			'id'         => $activity->id,
			'type'       => $activity->type->value,
			'title'      => $activity->title,
			'body'       => $activity->body,
			'url'        => $activity->url(),
			'user_id'    => $activity->wpUserId,
			'metadata'   => $activity->metadata,
			'created_at' => $activity->createdAt?->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Read the writable fields.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<string, mixed>
	 */
	private function input( WP_REST_Request $request ): array {
		$input = array();

		foreach ( array( 'email', 'first_name', 'last_name', 'phone', 'company', 'job_title', 'website', 'status' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = (string) $request->get_param( $key );
			}
		}

		if ( null !== $request->get_param( 'owner_user_id' ) ) {
			$input['owner_user_id'] = $request->get_param( 'owner_user_id' );
		}

		$fields = $request->get_param( 'custom_fields' );

		if ( is_array( $fields ) ) {
			// Re-cleaned here even though the route declared a sanitiser:
			// this lands in a JSON column that later feeds a scoring rule
			// target and, in Sprint 8, a CRM field mapping.
			$clean = array();

			foreach ( $fields as $key => $value ) {
				$key = sanitize_key( (string) $key );

				if ( '' !== $key && ( is_string( $value ) || is_numeric( $value ) ) ) {
					$clean[ $key ] = mb_substr( sanitize_textarea_field( (string) $value ), 0, 500 );
				}
			}

			$input['custom_fields'] = $clean;
		}

		return $input;
	}

	/**
	 * Read the list filters.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<string, mixed>
	 */
	private function filters( WP_REST_Request $request ): array {
		$filters = array();

		foreach ( array( 'status', 'band', 'source', 'search', 'date_from', 'date_to' ) as $key ) {
			$value = $this->stringParam( $request, $key );

			if ( null !== $value ) {
				$filters[ $key ] = $value;
			}
		}

		$stage = $request->get_param( 'stage_id' );

		if ( 'none' === $stage ) {
			$filters['stage_id'] = 'none';
		} elseif ( is_numeric( $stage ) ) {
			$filters['stage_id'] = (int) $stage;
		}

		$owner = $request->get_param( 'owner_user_id' );

		if ( 'none' === $owner ) {
			$filters['owner_user_id'] = 'none';
		} elseif ( is_numeric( $owner ) ) {
			$filters['owner_user_id'] = (int) $owner;
		}

		if ( null !== $request->get_param( 'min_score' ) ) {
			$filters['min_score'] = (int) $request->get_param( 'min_score' );
		}

		return $filters;
	}

	/**
	 * Arguments the list and export routes accept.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function listArgs(): array {
		return array_merge(
			$this->collectionArgs(),
			array(
				'status'        => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
				),
				'band'          => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
				),
				'source'        => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'stage_id'      => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'owner_user_id' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'min_score'     => array(
					'type'     => 'integer',
					'required' => false,
				),
				'order_by'      => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
				),
				'order'         => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
				),
				'date_from'     => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'date_to'       => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);
	}

	/**
	 * Arguments accepted when writing a lead.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function writeArgs(): array {
		return array(
			'email'         => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_email',
			),
			'first_name'    => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'last_name'     => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'phone'         => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'company'       => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'job_title'     => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'website'       => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'esc_url_raw',
			),
			'status'        => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
				// WordPress ignores `enum` unless a validate_callback is
				// registered alongside it.
				'validate_callback' => 'rest_validate_request_arg',
				'enum'              => array_map(
					static fn ( LeadStatus $status ): string => $status->value,
					LeadStatus::cases()
				),
			),
			'owner_user_id' => array(
				'type'     => 'integer',
				'required' => false,
			),
			'custom_fields' => array(
				'type'     => 'object',
				'required' => false,
			),
		);
	}
}
