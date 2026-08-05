<?php
/**
 * Clerk endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Agents\Http;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Core\Support\RateLimiter;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Agent\DisplayRules;
use Hiveclerk\Domain\Conversation\ConversationRepositoryInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Agents\Services\AgentService;
use Hiveclerk\Modules\Agents\Services\BudgetGuard;
use Hiveclerk\Modules\Agents\Services\PresetLibrary;
use Hiveclerk\Modules\Agents\Services\PublishPolicy;
use Hiveclerk\Modules\Agents\Services\TestConsoleService;
use Hiveclerk\Modules\Agents\Support\AgentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Everything the clerk editor and the roster talk to (D9 §3.2).
 *
 * The write routes take a partial object: a tab sends the fields it owns
 * and nothing else. That is not laziness on the client's side — the
 * editor has six tabs over one record, and a PATCH that carried the whole
 * clerk would let a stale tab overwrite a change made in another one, in
 * a screen designed to be edited a field at a time.
 */
final class AgentController extends AbstractController {

	/**
	 * Test console runs allowed per minute, per user.
	 *
	 * Every run is a real completion against the customer's own key
	 * (SEC-03). The limit is generous enough to iterate on a prompt and
	 * low enough that a stuck retry loop in the browser cannot spend the
	 * month's budget in an afternoon.
	 */
	private const TEST_LIMIT = 20;

	/**
	 * Days of history the roster cards summarise.
	 */
	private const STATS_DAYS = 30;

	/**
	 * Construct.
	 *
	 * @param AgentService                       $agents        Clerk lifecycle.
	 * @param AgentRepositoryInterface           $repository    Clerk storage.
	 * @param PresetLibrary                      $presets       Role presets.
	 * @param PublishPolicy                      $policy        Publishing limits.
	 * @param BudgetGuard                        $budget        Budget roll-over.
	 * @param TestConsoleService                 $console       Test console.
	 * @param ConversationRepositoryInterface    $conversations Conversation storage.
	 * @param KnowledgeSourceRepositoryInterface $sources       Knowledge sources.
	 * @param RateLimiter                        $limiter       Rate limiter.
	 * @param ClockInterface                     $clock         Clock.
	 */
	public function __construct(
		private readonly AgentService $agents,
		private readonly AgentRepositoryInterface $repository,
		private readonly PresetLibrary $presets,
		private readonly PublishPolicy $policy,
		private readonly BudgetGuard $budget,
		private readonly TestConsoleService $console,
		private readonly ConversationRepositoryInterface $conversations,
		private readonly KnowledgeSourceRepositoryInterface $sources,
		private readonly RateLimiter $limiter,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		$manage = $this->requires( Capabilities::MANAGE_AGENTS );
		$uuid   = '(?P<uuid>[a-f0-9-]{36})';

		register_rest_route(
			self::NAMESPACE,
			'/admin/agents',
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

		register_rest_route(
			self::NAMESPACE,
			'/admin/agents/presets',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'presets' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/agents/' . $uuid,
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

		foreach ( array( 'publish', 'pause', 'duplicate' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/admin/agents/' . $uuid . '/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $action ),
					'permission_callback' => $manage,
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/admin/agents/' . $uuid . '/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test' ),
				'permission_callback' => $manage,
				'args'                => array(
					'message' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'history' => array(
						'type'     => 'array',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * The roster.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->pagination( $request );

		$filters = array_filter(
			array(
				'status'      => $this->stringParam( $request, 'status' ),
				'role_preset' => $this->stringParam( $request, 'role' ),
				'search'      => $this->stringParam( $request, 'search' ),
			),
			static fn ( $value ): bool => null !== $value
		);

		$agents = $this->repository->paginate( $pagination, $filters );
		$stats  = $this->statsFor( $agents );
		$counts = $this->repository->sourceCounts( $this->idsOf( $agents ) );

		return ApiResponse::collection(
			array_map(
				fn ( Agent $agent ): array => $this->present( $this->budget->rollOver( $agent ), false, $stats, $counts ),
				$agents
			),
			$pagination,
			$this->repository->count( $filters ),
			array()
		);
	}

	/**
	 * The role presets, with their written instructions.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function presets( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return ApiResponse::ok(
			array(
				'presets' => $this->presets->toArray(),
				'licence' => array(
					'tier'      => $this->policy->tier(),
					'limit'     => $this->policy->limit(),
					'published' => $this->policy->published(),
				),
			)
		);
	}

	/**
	 * One clerk, in full.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$agent = $this->resolve( $request );

		if ( $agent instanceof WP_Error ) {
			return $agent;
		}

		return ApiResponse::ok( $this->present( $agent, true ) );
	}

	/**
	 * Hire a clerk.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$agent = $this->agents->create( $this->input( $request ) );
		} catch ( AgentException $e ) {
			return ApiResponse::error( $e->errorCode, $e->getMessage(), $e->status );
		}

		return ApiResponse::ok( $this->present( $agent, true ), array(), 201 );
	}

	/**
	 * Change a clerk.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$agent = $this->resolve( $request );

		if ( $agent instanceof WP_Error ) {
			return $agent;
		}

		try {
			$agent = $this->agents->update( $agent, $this->input( $request ) );
		} catch ( AgentException $e ) {
			return ApiResponse::error( $e->errorCode, $e->getMessage(), $e->status );
		}

		return ApiResponse::ok( $this->present( $agent, true ) );
	}

	/**
	 * Put a clerk on duty.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function publish( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$agent = $this->resolve( $request );

		if ( $agent instanceof WP_Error ) {
			return $agent;
		}

		try {
			$agent = $this->agents->publish( $agent );
		} catch ( AgentException $e ) {
			return ApiResponse::error( $e->errorCode, $e->getMessage(), $e->status );
		}

		return ApiResponse::ok( $this->present( $agent, true ) );
	}

	/**
	 * Take a clerk off duty.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pause( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$agent = $this->resolve( $request );

		if ( $agent instanceof WP_Error ) {
			return $agent;
		}

		return ApiResponse::ok( $this->present( $this->agents->pause( $agent ), true ) );
	}

	/**
	 * Copy a clerk.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$agent = $this->resolve( $request );

		if ( $agent instanceof WP_Error ) {
			return $agent;
		}

		return ApiResponse::ok( $this->present( $this->agents->duplicate( $agent ), true ), array(), 201 );
	}

	/**
	 * Retire a clerk.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$agent = $this->resolve( $request );

		if ( $agent instanceof WP_Error ) {
			return $agent;
		}

		$this->agents->delete( $agent );

		return ApiResponse::ok( array( 'deleted' => true ) );
	}

	/**
	 * Run the clerk without touching the live site (FR-CLK-08).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$agent = $this->resolve( $request );

		if ( $agent instanceof WP_Error ) {
			return $agent;
		}

		$throttled = $this->throttle(
			$this->limiter,
			'agent_test|' . get_current_user_id(),
			self::TEST_LIMIT
		);

		if ( $throttled instanceof WP_Error ) {
			return $throttled;
		}

		$message = $this->stringParam( $request, 'message' );

		if ( null === $message ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				__( 'Type something to ask the clerk.', 'hiveclerk' ),
				422
			);
		}

		$history = $request->get_param( 'history' );

		$result = $this->console->run(
			$agent,
			$message,
			is_array( $history ) ? $this->history( $history ) : array()
		);

		return ApiResponse::ok( $result, array(), 200, $throttled );
	}

	/**
	 * Resolve the clerk named in the route.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return Agent|WP_Error
	 */
	private function resolve( WP_REST_Request $request ): Agent|WP_Error {
		$uuid = (string) $request->get_param( 'uuid' );

		if ( ! Uuid::isValid( $uuid ) ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				__( 'That is not a valid clerk id.', 'hiveclerk' ),
				422
			);
		}

		$agent = $this->agents->findByUuid( new Uuid( $uuid ) );

		if ( null === $agent || null === $agent->id ) {
			return ApiResponse::error(
				ErrorCode::NOT_FOUND,
				__( 'That clerk does not exist.', 'hiveclerk' ),
				404
			);
		}

		return $agent;
	}

	/**
	 * Shape a clerk for the browser.
	 *
	 * @param Agent                                                                           $agent    The clerk.
	 * @param bool                                                                            $detailed Whether to include the editor's fields.
	 * @param array<int, array{conversations: int, resolved: int, handoffs: int, cost: float}> $stats    Per-clerk totals.
	 * @param array<int, int>                                                                 $counts   Source counts, keyed by clerk id.
	 * @return array<string, mixed>
	 */
	private function present(
		Agent $agent,
		bool $detailed = false,
		array $stats = array(),
		?array $counts = null
	): array {
		$id = (int) $agent->id;

		// The list is handed batched counts; the detail view needs the ids
		// themselves and is one clerk, so it reads them directly.
		$sources = null === $counts && $id > 0 ? $this->repository->sourceIds( $id ) : array();
		$totals  = $stats[ $id ] ?? null;
		$preset  = $this->presets->get( $agent->rolePreset );

		$data = array(
			'uuid'         => $agent->uuid->value,
			'name'         => $agent->name,
			'slug'         => $agent->slug,
			'role'         => $agent->rolePreset,
			'role_label'   => null === $preset ? $agent->rolePreset : $preset->label,
			'status'       => $agent->status->value,
			'status_label' => $agent->status->label(),
			'avatar_url'   => $agent->avatarUrl,
			'source_count' => count( $sources ),
			'budget'       => $this->budget->describe( $agent ),
			'created_at'   => $agent->createdAt?->format( 'Y-m-d H:i:s' ),
			// Null rather than zeroes when nothing has happened yet. A
			// roster card showing "0 conversations · 0% resolved" for a
			// clerk hired an hour ago reads as a clerk that is failing.
			'stats'        => null === $totals ? null : array(
				'days'          => self::STATS_DAYS,
				'conversations' => $totals['conversations'],
				'resolved'      => $totals['resolved'],
				'handoffs'      => $totals['handoffs'],
				'cost'          => round( $totals['cost'], 4 ),
			),
		);

		if ( ! $detailed ) {
			return $data;
		}

		return array_merge(
			$data,
			array(
				'greeting'         => $agent->greeting,
				'token_budget'     => $agent->tokenBudget,
				'fallback_message' => $agent->fallbackMessage,
				'instructions'     => $agent->instructions,
				'model_config'     => $agent->modelConfig,
				'guardrails'       => $agent->guardrails,
				'personality'      => $agent->personality,
				'display_rules'    => $agent->displayRules()->toArray(),
				'appears'          => $agent->displayRules()->isUnrestricted() ? 'everywhere' : 'rules',
				'widget_config'    => $agent->widgetConfig,
				'lead_config'      => $agent->leadConfig,
				'source_ids'       => $sources,
				'sources'          => $this->sourceSummaries( $sources ),
				'source_uuids'     => array_values(
					array_map(
						static fn ( array $source ): string => (string) $source['uuid'],
						$this->sourceSummaries( $sources )
					)
				),
				'blockers'         => $this->agents->blockers( $agent ),
				'can_publish'      => $this->policy->allowsPublishing( $agent ),
			)
		);
	}

	/**
	 * Storage ids for a page of clerks.
	 *
	 * @param array<int, Agent> $agents Clerks.
	 * @return array<int, int>
	 */
	private function idsOf( array $agents ): array {
		$ids = array();

		foreach ( $agents as $agent ) {
			if ( null !== $agent->id ) {
				$ids[] = $agent->id;
			}
		}

		return $ids;
	}

	/**
	 * Names for the knowledge sources a clerk reads.
	 *
	 * @param array<int, int> $sourceIds Source ids.
	 * @return array<int, array<string, mixed>>
	 */
	private function sourceSummaries( array $sourceIds ): array {
		$summaries = array();

		foreach ( $sourceIds as $sourceId ) {
			$source = $this->sources->find( $sourceId );

			if ( null === $source ) {
				continue;
			}

			$summaries[] = array(
				'id'          => $sourceId,
				'uuid'        => $source->uuid->value,
				'name'        => $source->name,
				'type_label'  => $source->type->label(),
				'chunk_count' => $source->chunkCount,
			);
		}

		return $summaries;
	}

	/**
	 * Recent totals for a page of clerks, in one query.
	 *
	 * @param array<int, Agent> $agents Clerks.
	 * @return array<int, array{conversations: int, resolved: int, handoffs: int, cost: float}>
	 */
	private function statsFor( array $agents ): array {
		$ids = array();

		foreach ( $agents as $agent ) {
			if ( null !== $agent->id ) {
				$ids[] = $agent->id;
			}
		}

		if ( array() === $ids ) {
			return array();
		}

		$since = $this->clock->now()
			->modify( sprintf( '-%d days', self::STATS_DAYS ) )
			->format( 'Y-m-d H:i:s' );

		return $this->conversations->statsForAgents( $ids, $since );
	}

	/**
	 * Read and clean the writable fields.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<string, mixed>
	 */
	private function input( WP_REST_Request $request ): array {
		$input = array();

		foreach ( array( 'name', 'role_preset', 'avatar_url' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = (string) $request->get_param( $key );
			}
		}

		// Multi-line by nature: a greeting runs to two lines and a job
		// description to a page. sanitize_text_field() would flatten both
		// into one line and nobody would notice until a customer read it.
		foreach ( array( 'greeting', 'fallback_message', 'instructions' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = sanitize_textarea_field( (string) $request->get_param( $key ) );
			}
		}

		if ( null !== $request->get_param( 'token_budget' ) ) {
			$input['token_budget'] = (int) $request->get_param( 'token_budget' );
		}

		foreach ( array( 'model_config', 'guardrails', 'widget_config', 'personality', 'lead_config' ) as $key ) {
			$value = $request->get_param( $key );

			if ( is_array( $value ) ) {
				$input[ $key ] = $this->clean( $value );
			}
		}

		$rules = $request->get_param( 'display_rules' );

		if ( is_array( $rules ) ) {
			// Normalised through the same value object that evaluates them,
			// so what is stored is exactly what will be enforced. Cleaning
			// them a second way here is how a rule that reads correctly in
			// the editor comes to behave differently on the site.
			$input['display_rules'] = DisplayRules::fromArray( $this->clean( $rules ) )->toArray();
		}

		$sources = $request->get_param( 'source_uuids' );

		if ( is_array( $sources ) ) {
			// The editor works in uuids because that is what the knowledge
			// API exposes; storage ids are resolved here rather than being
			// published as a second way to address a source.
			$input['source_ids'] = $this->resolveSources( $sources );
		}

		return $input;
	}

	/**
	 * Turn a list of knowledge-source uuids into storage ids.
	 *
	 * A uuid that names nothing is dropped rather than refused. The list
	 * arrives from a screen that was rendered before the save, and a
	 * source deleted in between must not cost the operator every other
	 * change on the form.
	 *
	 * @param array<mixed> $uuids Raw uuids.
	 * @return array<int, int>
	 */
	private function resolveSources( array $uuids ): array {
		$ids = array();

		foreach ( $uuids as $value ) {
			if ( ! is_string( $value ) || ! Uuid::isValid( $value ) ) {
				continue;
			}

			$source = $this->sources->findByUuid( new Uuid( $value ) );

			if ( null !== $source && null !== $source->id ) {
				$ids[] = $source->id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Sanitise nested configuration.
	 *
	 * @param array<mixed> $value Raw value.
	 * @return array<string, mixed>
	 */
	private function clean( array $value ): array {
		$clean = array();

		foreach ( $value as $key => $item ) {
			$key = is_int( $key ) ? (string) $key : sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $item ) ) {
				$clean[ $key ] = $this->clean( $item );

				continue;
			}

			if ( is_bool( $item ) || is_int( $item ) || is_float( $item ) ) {
				$clean[ $key ] = $item;

				continue;
			}

			$clean[ $key ] = sanitize_textarea_field( (string) $item );
		}

		return $clean;
	}

	/**
	 * Clean the console's replayed history.
	 *
	 * @param array<mixed> $history Raw history.
	 * @return array<int, array<string, string>>
	 */
	private function history( array $history ): array {
		$turns = array();

		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$content = $entry['content'] ?? '';

			if ( ! is_string( $content ) || '' === trim( $content ) ) {
				continue;
			}

			$turns[] = array(
				'role'    => 'assistant' === ( $entry['role'] ?? '' ) ? 'assistant' : 'visitor',
				'content' => sanitize_textarea_field( $content ),
			);
		}

		return $turns;
	}

	/**
	 * Arguments the roster accepts.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function listArgs(): array {
		return array_merge(
			$this->collectionArgs(),
			array(
				'status' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
				),
				'role'   => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
				),
			)
		);
	}

	/**
	 * Arguments accepted when writing a clerk.
	 *
	 * Every field is optional, including on create: the roster's "hire a
	 * clerk" button sends a role and nothing else, and the preset fills
	 * the rest.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function writeArgs(): array {
		return array(
			'name'             => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'role_preset'      => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			),
			'avatar_url'       => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'esc_url_raw',
			),
			'greeting'         => array(
				'type'     => 'string',
				'required' => false,
			),
			'fallback_message' => array(
				'type'     => 'string',
				'required' => false,
			),
			'instructions'     => array(
				'type'     => 'string',
				'required' => false,
			),
			'token_budget'     => array(
				'type'     => 'integer',
				'required' => false,
			),
			'model_config'     => array(
				'type'     => 'object',
				'required' => false,
			),
			'guardrails'       => array(
				'type'     => 'object',
				'required' => false,
			),
			'personality'      => array(
				'type'     => 'object',
				'required' => false,
			),
			'display_rules'    => array(
				'type'     => 'object',
				'required' => false,
			),
			'widget_config'    => array(
				'type'     => 'object',
				'required' => false,
			),
			'lead_config'      => array(
				'type'     => 'object',
				'required' => false,
			),
			'source_uuids'     => array(
				'type'     => 'array',
				'required' => false,
			),
		);
	}
}
