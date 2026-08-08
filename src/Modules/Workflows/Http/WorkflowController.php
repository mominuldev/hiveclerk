<?php
/**
 * Workflow endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Workflows\Http;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Licence\Feature;
use Hiveclerk\Core\Licence\LicenceGate;
use Hiveclerk\Domain\Email\SequenceRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadRepositoryInterface;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Domain\Workflow\ActionType;
use Hiveclerk\Domain\Workflow\ConditionField;
use Hiveclerk\Domain\Workflow\ConditionOperator;
use Hiveclerk\Domain\Workflow\RunLogRepositoryInterface;
use Hiveclerk\Domain\Workflow\RunStatus;
use Hiveclerk\Domain\Workflow\TriggerEvent;
use Hiveclerk\Domain\Workflow\Workflow;
use Hiveclerk\Domain\Workflow\WorkflowRepositoryInterface;
use Hiveclerk\Domain\Workflow\WorkflowRun;
use Hiveclerk\Domain\Workflow\WorkflowGraph;
use Hiveclerk\Domain\Workflow\WorkflowRunRepositoryInterface;
use Hiveclerk\Modules\Workflows\Services\ActionRegistry;
use Hiveclerk\Modules\Workflows\Services\WorkflowService;
use Hiveclerk\Modules\Workflows\Services\WorkflowSimulator;
use Hiveclerk\Modules\Workflows\Services\WorkflowTemplates;
use Hiveclerk\Modules\Workflows\Support\GraphSanitizer;
use Hiveclerk\Modules\Workflows\Support\Placeholders;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The Workflows surface (D9 §3.9).
 *
 * Every route requires `manage_workflows`, which only administrators hold
 * by default. That is stricter than the rest of the automation surface
 * and deliberately so: a workflow can reach a CRM, a webhook endpoint and
 * a mailing list, so the builder is a superset of three capabilities the
 * role map hands out separately.
 *
 * The licence gate sits on the writes rather than the reads. A site whose
 * licence has lapsed can still see what its workflows were doing and why
 * a lead received what it received — taking the evidence away along with
 * the feature would make an expired card into a support incident.
 */
final class WorkflowController extends AbstractController {

	/**
	 * Construct.
	 *
	 * @param WorkflowService                $service   Workflow management.
	 * @param WorkflowRepositoryInterface    $workflows Workflow storage.
	 * @param WorkflowRunRepositoryInterface $runs      Run storage.
	 * @param RunLogRepositoryInterface      $log       Run log.
	 * @param WorkflowSimulator              $simulator Dry runs.
	 * @param ActionRegistry                 $actions   Available actions.
	 * @param LeadStageRepositoryInterface   $stages    Stage vocabulary.
	 * @param SequenceRepositoryInterface    $sequences Sequence vocabulary.
	 * @param LeadRepositoryInterface        $leads     Leads, for dry runs.
	 * @param LicenceGate                    $licence   Tier entitlements.
	 */
	public function __construct(
		private readonly WorkflowService $service,
		private readonly WorkflowRepositoryInterface $workflows,
		private readonly WorkflowRunRepositoryInterface $runs,
		private readonly RunLogRepositoryInterface $log,
		private readonly WorkflowSimulator $simulator,
		private readonly ActionRegistry $actions,
		private readonly LeadStageRepositoryInterface $stages,
		private readonly SequenceRepositoryInterface $sequences,
		private readonly LeadRepositoryInterface $leads,
		private readonly LicenceGate $licence
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		$manage = $this->requires( Capabilities::MANAGE_WORKFLOWS );

		register_rest_route(
			self::NAMESPACE,
			'/admin/workflows',
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
					'args'                => array(
						'name'     => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'trigger'  => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
						'template' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		// Before the {uuid} patterns: WordPress matches in registration
		// order, and both of these would otherwise be read as identifiers.
		register_rest_route(
			self::NAMESPACE,
			'/admin/workflows/vocabulary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'vocabulary' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/workflows/(?P<uuid>[0-9a-f-]{36})',
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
					'args'                => array(
						'name'           => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'trigger'        => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
						'trigger_config' => array(
							'type'     => 'object',
							'required' => false,
						),
						'graph'          => array(
							'type'     => 'object',
							'required' => false,
						),
						'runs_once'      => array(
							'type'     => 'boolean',
							'required' => false,
						),
					),
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
			'/admin/workflows/(?P<uuid>[0-9a-f-]{36})/(?P<action>activate|pause)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'setStatus' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/workflows/(?P<uuid>[0-9a-f-]{36})/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test' ),
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
			'/admin/workflows/(?P<uuid>[0-9a-f-]{36})/runs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'runs' ),
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

		register_rest_route(
			self::NAMESPACE,
			'/admin/workflows/runs/(?P<id>[0-9]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => $manage,
			)
		);
	}

	/**
	 * List workflows.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->pagination( $request );
		$workflows  = $this->workflows->paginate( $pagination );

		return ApiResponse::ok(
			array(
				'workflows' => array_map( fn ( Workflow $w ): array => $this->summary( $w ), $workflows ),
				'templates' => WorkflowTemplates::all(),
				'entitled'  => $this->licence->allows( Feature::Workflows ),
				'total'     => $this->workflows->countAll(),
			),
			array(
				'pagination' => array(
					'page'        => $pagination->page,
					'per_page'    => $pagination->perPage,
					'total'       => $this->workflows->countAll(),
					'total_pages' => $pagination->totalPages( $this->workflows->countAll() ),
				),
			)
		);
	}

	/**
	 * Create one, optionally from a template.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$refusal = $this->licence->refusal( Feature::Workflows );

		if ( null !== $refusal ) {
			return $refusal;
		}

		$template = $this->stringParam( $request, 'template' );
		$name     = $this->stringParam( $request, 'name' );

		if ( null !== $template ) {
			$graph = WorkflowTemplates::graphFor( $template );

			if ( null === $graph ) {
				return ApiResponse::error(
					ErrorCode::VALIDATION_FAILED,
					__( 'That template does not exist.', 'hiveclerk' ),
					422
				);
			}

			$workflow = $this->service->create(
				$name ?? $this->templateName( $template ),
				WorkflowTemplates::triggerFor( $template ) ?? TriggerEvent::LeadCaptured,
				$graph,
				WorkflowTemplates::configFor( $template )
			);

			return ApiResponse::ok( $this->detail( $workflow ), array(), 201 );
		}

		$trigger = TriggerEvent::tryFrom( (string) $this->stringParam( $request, 'trigger' ) );

		$workflow = $this->service->create(
			$name ?? __( 'Untitled workflow', 'hiveclerk' ),
			$trigger ?? TriggerEvent::LeadCaptured
		);

		return ApiResponse::ok( $this->detail( $workflow ), array(), 201 );
	}

	/**
	 * One workflow, with its graph and its blockers.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$workflow = $this->workflow( $request );

		if ( $workflow instanceof WP_Error ) {
			return $workflow;
		}

		return ApiResponse::ok( $this->detail( $workflow ) );
	}

	/**
	 * Apply changes.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$refusal = $this->licence->refusal( Feature::Workflows );

		if ( null !== $refusal ) {
			return $refusal;
		}

		$workflow = $this->workflow( $request );

		if ( $workflow instanceof WP_Error ) {
			return $workflow;
		}

		$changes = array();

		if ( null !== $request->get_param( 'name' ) ) {
			$changes['name'] = (string) $request->get_param( 'name' );
		}

		if ( null !== $request->get_param( 'trigger' ) ) {
			$changes['trigger'] = (string) $request->get_param( 'trigger' );
		}

		if ( is_array( $request->get_param( 'trigger_config' ) ) ) {
			$changes['trigger_config'] = $this->cleanTriggerConfig(
				$request->get_param( 'trigger_config' )
			);
		}

		if ( null !== $request->get_param( 'graph' ) ) {
			$changes['graph'] = GraphSanitizer::clean( $request->get_param( 'graph' ) );
		}

		if ( null !== $request->get_param( 'runs_once' ) ) {
			$changes['runs_once'] = (bool) $request->get_param( 'runs_once' );
		}

		return ApiResponse::ok( $this->detail( $this->service->update( $workflow, $changes ) ) );
	}

	/**
	 * Activate or pause.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function setStatus( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$refusal = $this->licence->refusal( Feature::Workflows );

		if ( null !== $refusal ) {
			return $refusal;
		}

		$workflow = $this->workflow( $request );

		if ( $workflow instanceof WP_Error ) {
			return $workflow;
		}

		if ( 'pause' === $request->get_param( 'action' ) ) {
			return ApiResponse::ok( $this->detail( $this->service->pause( $workflow ) ) );
		}

		$blockers = $this->service->activate( $workflow );

		if ( array() !== $blockers ) {
			// The reason is the whole value of the gate. A 422 that says
			// "validation failed" would send the operator back to a screen
			// with nine steps on it and no idea which one is wrong.
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				$blockers[0]['message'],
				422,
				array( 'blockers' => array_column( $blockers, 'message' ) )
			);
		}

		return ApiResponse::ok( $this->detail( $workflow ) );
	}

	/**
	 * Delete.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$workflow = $this->workflow( $request );

		if ( $workflow instanceof WP_Error ) {
			return $workflow;
		}

		$cancelled = $this->service->delete( $workflow );

		return ApiResponse::ok(
			array(
				'deleted'        => true,
				'runs_cancelled' => $cancelled,
			)
		);
	}

	/**
	 * Dry run against a real lead.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$workflow = $this->workflow( $request );

		if ( $workflow instanceof WP_Error ) {
			return $workflow;
		}

		$uuid = $this->stringParam( $request, 'lead' );
		$lead = null;

		if ( null !== $uuid && Uuid::isValid( $uuid ) ) {
			$lead = $this->leads->findByUuid( new Uuid( $uuid ) );

			if ( null === $lead ) {
				return ApiResponse::error(
					ErrorCode::NOT_FOUND,
					__( 'That lead no longer exists.', 'hiveclerk' ),
					404
				);
			}
		}

		return ApiResponse::ok(
			array(
				'trace'    => $this->simulator->simulate( $workflow, $lead ),
				'lead'     => null === $lead ? null : array(
					'uuid' => $lead->uuid->value,
					'name' => $lead->displayName(),
				),
				'executed' => false,
			)
		);
	}

	/**
	 * A workflow's runs.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function runs( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$workflow = $this->workflow( $request );

		if ( $workflow instanceof WP_Error ) {
			return $workflow;
		}

		$pagination = $this->pagination( $request );
		$counts     = $this->runs->countsByStatus( (int) $workflow->id );

		$rows = $this->runs->forWorkflow(
			(int) $workflow->id,
			$pagination,
			$this->stringParam( $request, 'status' )
		);

		return ApiResponse::collection(
			array_map( fn ( WorkflowRun $run ): array => $this->runSummary( $run ), $rows ),
			$pagination,
			array_sum( $counts )
		);
	}

	/**
	 * One run and everything it did.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$run = $this->runs->find( (int) $request->get_param( 'id' ) );

		if ( null === $run ) {
			return ApiResponse::error(
				ErrorCode::NOT_FOUND,
				__( 'That run has been cleared. Runs are kept for 90 days.', 'hiveclerk' ),
				404
			);
		}

		$entries = null === $run->id ? array() : $this->log->forRun( $run->id );

		return ApiResponse::ok(
			array(
				'run' => $this->runSummary( $run ),
				'log' => array_map(
					static fn ( $entry ): array => array(
						'node'       => $entry->nodeId,
						'node_type'  => $entry->nodeType->value,
						'outcome'    => $entry->outcome->value,
						'label'      => $entry->outcome->label(),
						'detail'     => $entry->detail,
						'created_at' => $entry->createdAt?->format( 'c' ),
					),
					$entries
				),
			)
		);
	}

	/**
	 * Everything the builder needs to render its pickers.
	 *
	 * One request rather than five, because the builder needs all of it
	 * before it can draw a single node, and five round trips on a screen
	 * with a spinner is how a fast product feels slow.
	 *
	 * @return WP_REST_Response
	 */
	public function vocabulary(): WP_REST_Response {
		return ApiResponse::ok(
			array(
				'triggers'     => array_map(
					static fn ( TriggerEvent $t ): array => array(
						'value'       => $t->value,
						'label'       => $t->label(),
						'description' => $t->description(),
						'subject'     => $t->subject()->value,
						'needs_stage' => $t->needsStage(),
						'scheduled'   => $t->isScheduled(),
					),
					TriggerEvent::cases()
				),
				'actions'      => array_map(
					fn ( ActionType $a ): array => array(
						'value'       => $a->value,
						'label'       => $a->label(),
						'description' => $a->description(),
						'available'   => $this->actions->has( $a ),
						'subjects'    => array_map(
							static fn ( $s ): string => $s->value,
							$a->subjects()
						),
						'outbound'    => $a->reachesOutside(),
					),
					ActionType::cases()
				),
				'fields'       => array_map(
					static fn ( ConditionField $f ): array => array(
						'value'     => $f->value,
						'label'     => $f->label(),
						'numeric'   => $f->isNumeric(),
						'needs_key' => $f->needsKey(),
					),
					ConditionField::cases()
				),
				'operators'    => array_map(
					static fn ( ConditionOperator $o ): array => array(
						'value'       => $o->value,
						'label'       => $o->label(),
						'needs_value' => $o->needsValue(),
						'numeric'     => $o->isNumeric(),
					),
					ConditionOperator::cases()
				),
				'stages'       => array_map(
					static fn ( $stage ): array => array(
						'id'   => $stage->id,
						'name' => $stage->name,
					),
					$this->stages->all()
				),
				'sequences'    => array_map(
					static fn ( $sequence ): array => array(
						'uuid'   => $sequence->uuid->value,
						'name'   => $sequence->name,
						'active' => $sequence->isActive(),
					),
					$this->sequences->paginate( new Pagination( 1, 50 ) )
				),
				'tags'         => Placeholders::available(),
				'max_nodes'    => WorkflowGraph::MAX_NODES,
				'min_interval' => Workflow::MIN_INTERVAL_MINUTES,
			)
		);
	}

	/**
	 * The workflow named in the route, or a 404.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return Workflow|WP_Error
	 */
	private function workflow( WP_REST_Request $request ): Workflow|WP_Error {
		$uuid = (string) $request->get_param( 'uuid' );

		if ( ! Uuid::isValid( $uuid ) ) {
			return ApiResponse::error(
				ErrorCode::NOT_FOUND,
				__( 'That workflow does not exist.', 'hiveclerk' ),
				404
			);
		}

		$workflow = $this->workflows->findByUuid( new Uuid( $uuid ) );

		if ( null === $workflow || null !== $workflow->deletedAt || null === $workflow->id ) {
			return ApiResponse::error(
				ErrorCode::NOT_FOUND,
				__( 'That workflow does not exist.', 'hiveclerk' ),
				404
			);
		}

		return $workflow;
	}

	/**
	 * The list-screen shape.
	 *
	 * @param Workflow $workflow Workflow.
	 * @return array<string, mixed>
	 */
	private function summary( Workflow $workflow ): array {
		$counts = null === $workflow->id ? array() : $this->runs->countsByStatus( $workflow->id );

		return array(
			'uuid'          => $workflow->uuid->value,
			'name'          => $workflow->name,
			'status'        => $workflow->status->value,
			'status_label'  => $workflow->status->label(),
			'trigger'       => $workflow->trigger->value,
			'trigger_label' => $workflow->trigger->label(),
			'steps'         => max( 0, $workflow->graph->size() - 1 ),
			'runs_once'     => $workflow->runsOnce,
			'run_count'     => $workflow->runCount,
			'last_run_at'   => $workflow->lastRunAt?->format( 'c' ),
			'next_run_at'   => $workflow->nextRunAt?->format( 'c' ),
			'runs'          => array(
				'waiting'   => ( $counts[ RunStatus::Waiting->value ] ?? 0 ) + ( $counts[ RunStatus::Pending->value ] ?? 0 ),
				'completed' => $counts[ RunStatus::Completed->value ] ?? 0,
				'failed'    => $counts[ RunStatus::Failed->value ] ?? 0,
			),
			'created_at'    => $workflow->createdAt?->format( 'c' ),
		);
	}

	/**
	 * The builder shape.
	 *
	 * @param Workflow $workflow Workflow.
	 * @return array<string, mixed>
	 */
	private function detail( Workflow $workflow ): array {
		$blockers = $this->service->blockers( $workflow );

		return array_merge(
			$this->summary( $workflow ),
			array(
				'graph'          => $workflow->graph->toArray(),
				'trigger_config' => $workflow->triggerConfig,
				'interval'       => $workflow->intervalMinutes(),
				'segment'        => $workflow->segment(),
				'blockers'       => $blockers,
				'can_activate'   => array() === $blockers,
			)
		);
	}

	/**
	 * The runs-screen shape.
	 *
	 * @param WorkflowRun $run Run.
	 * @return array<string, mixed>
	 */
	private function runSummary( WorkflowRun $run ): array {
		return array(
			'id'           => $run->id,
			'status'       => $run->status->value,
			'status_label' => $run->status->label(),
			'subject_type' => $run->subjectType->value,
			'subject_name' => $this->subjectName( $run ),
			'current_node' => $run->currentNode,
			'steps'        => $run->steps,
			'error'        => $run->error,
			'resume_at'    => $run->resumeAt?->format( 'c' ),
			'started_at'   => $run->startedAt?->format( 'c' ),
			'finished_at'  => $run->finishedAt?->format( 'c' ),
		);
	}

	/**
	 * Who a run is about, as a name rather than an id.
	 *
	 * @param WorkflowRun $run Run.
	 * @return string|null
	 */
	private function subjectName( WorkflowRun $run ): ?string {
		$stored = $run->context['lead.name'] ?? null;

		if ( is_string( $stored ) && '' !== $stored ) {
			// The name as it was at the trigger. A lead deleted since then
			// still has a name here, which is what makes a run against an
			// erased lead readable rather than a bare id.
			return $stored;
		}

		return null;
	}

	/**
	 * Clean the trigger configuration.
	 *
	 * @param mixed $raw Whatever arrived.
	 * @return array<string, mixed>
	 */
	private function cleanTriggerConfig( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();

		if ( isset( $raw['stage_id'] ) ) {
			$clean['stage_id'] = absint( $raw['stage_id'] );
		}

		if ( isset( $raw['interval'] ) ) {
			$clean['interval'] = max( Workflow::MIN_INTERVAL_MINUTES, absint( $raw['interval'] ) );
		}

		if ( isset( $raw['segment'] ) && is_array( $raw['segment'] ) ) {
			$clean['segment'] = $this->cleanSegment( $raw['segment'] );
		}

		return $clean;
	}

	/**
	 * Clean a lead segment filter.
	 *
	 * Key by key against what the lead repository understands. A key it
	 * does not recognise is ignored there, and an ignored filter widens
	 * the segment rather than narrowing it — the difference between
	 * emailing forty people and emailing forty thousand.
	 *
	 * @param array<string, mixed> $raw Filter as sent.
	 * @return array<string, mixed>
	 */
	private function cleanSegment( array $raw ): array {
		$clean = array();

		if ( isset( $raw['stage_id'] ) && '' !== $raw['stage_id'] ) {
			$clean['stage_id'] = 'none' === $raw['stage_id'] ? 'none' : absint( $raw['stage_id'] );
		}

		foreach ( array( 'status', 'band', 'source' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && '' !== $raw[ $key ] ) {
				$clean[ $key ] = sanitize_text_field( $raw[ $key ] );
			}
		}

		if ( isset( $raw['min_score'] ) && is_numeric( $raw['min_score'] ) ) {
			$clean['min_score'] = absint( $raw['min_score'] );
		}

		if ( isset( $raw['has_email'] ) ) {
			$clean['has_email'] = (bool) $raw['has_email'];
		}

		return $clean;
	}

	/**
	 * A template's name.
	 *
	 * @param string $id Template id.
	 * @return string
	 */
	private function templateName( string $id ): string {
		foreach ( WorkflowTemplates::all() as $template ) {
			if ( $template['id'] === $id ) {
				return $template['name'];
			}
		}

		return __( 'Untitled workflow', 'hiveclerk' );
	}
}
