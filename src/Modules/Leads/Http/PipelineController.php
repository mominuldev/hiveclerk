<?php
/**
 * Pipeline stage and scoring-rule endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Http;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Domain\Lead\LeadStage;
use Hiveclerk\Domain\Lead\LeadStageRepositoryInterface;
use Hiveclerk\Domain\Lead\Scoring\RuleKind;
use Hiveclerk\Domain\Lead\Scoring\RuleOperator;
use Hiveclerk\Domain\Lead\Scoring\RuleSet;
use Hiveclerk\Modules\Leads\Services\PipelineService;
use Hiveclerk\Modules\Leads\Services\ScoringPolicy;
use Hiveclerk\Modules\Leads\Support\LeadException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The board's columns and the site's scoring policy (D9 §3.5).
 *
 * Both are configuration rather than data, which is why every write here
 * lands in the audit log while a lead moving between columns lands on
 * the lead's own timeline.
 */
final class PipelineController extends AbstractController {

	/**
	 * Construct.
	 *
	 * @param PipelineService              $pipeline Stage management.
	 * @param LeadStageRepositoryInterface $stages   Stage storage.
	 * @param ScoringPolicy                $policy   Rules, bands and alerts.
	 * @param AuditLogger                  $audit    Audit log.
	 */
	public function __construct(
		private readonly PipelineService $pipeline,
		private readonly LeadStageRepositoryInterface $stages,
		private readonly ScoringPolicy $policy,
		private readonly AuditLogger $audit
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
			'/admin/leads/stages',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $manage,
					'args'                => $this->stageArgs(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/leads/stages/reorder',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reorder' ),
				'permission_callback' => $manage,
				'args'                => array(
					'order' => array(
						'type'     => 'array',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/leads/stages/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $manage,
					'args'                => $this->stageArgs(),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $manage,
					'args'                => array(
						'move_to' => array(
							'type'     => 'integer',
							'required' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/leads/scoring-rules',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rules' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'saveRules' ),
					'permission_callback' => $manage,
					'args'                => array(
						'rules'  => array(
							'type'     => 'array',
							'required' => false,
						),
						'bands'  => array(
							'type'     => 'object',
							'required' => false,
						),
						'alerts' => array(
							'type'     => 'object',
							'required' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Every column, with what is in it.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return ApiResponse::ok(
			array(
				'stages' => array_map(
					static fn ( LeadStage $stage ): array => LeadController::presentStage( $stage ),
					$this->pipeline->all()
				),
				'counts' => $this->pipeline->counts(),
			)
		);
	}

	/**
	 * Add a column.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$stage = $this->pipeline->create( $this->stageInput( $request ) );
		} catch ( LeadException $e ) {
			return ApiResponse::error( $e->errorCode, $e->getMessage(), $e->status );
		}

		return ApiResponse::ok( LeadController::presentStage( $stage ), array(), 201 );
	}

	/**
	 * Change a column.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$stage = $this->stages->find( (int) $request->get_param( 'id' ) );

		if ( null === $stage ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, __( 'That stage does not exist.', 'hiveclerk' ), 404 );
		}

		try {
			$stage = $this->pipeline->update( $stage, $this->stageInput( $request ) );
		} catch ( LeadException $e ) {
			return ApiResponse::error( $e->errorCode, $e->getMessage(), $e->status );
		}

		return ApiResponse::ok( LeadController::presentStage( $stage ) );
	}

	/**
	 * Remove a column, moving what was in it.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$stage = $this->stages->find( (int) $request->get_param( 'id' ) );

		if ( null === $stage ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, __( 'That stage does not exist.', 'hiveclerk' ), 404 );
		}

		$moveTo = $request->get_param( 'move_to' );

		try {
			$moved = $this->pipeline->delete(
				$stage,
				is_numeric( $moveTo ) && (int) $moveTo > 0 ? (int) $moveTo : null
			);
		} catch ( LeadException $e ) {
			return ApiResponse::error( $e->errorCode, $e->getMessage(), $e->status );
		}

		return ApiResponse::ok(
			array(
				'deleted'     => true,
				'leads_moved' => $moved,
			)
		);
	}

	/**
	 * Write a new left-to-right order.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function reorder( WP_REST_Request $request ): WP_REST_Response {
		$order = $request->get_param( 'order' );
		$ids   = array();

		foreach ( is_array( $order ) ? $order : array() as $id ) {
			if ( is_numeric( $id ) ) {
				$ids[] = (int) $id;
			}
		}

		return ApiResponse::ok(
			array_map(
				static fn ( LeadStage $stage ): array => LeadController::presentStage( $stage ),
				$this->pipeline->reorder( $ids )
			)
		);
	}

	/**
	 * The scoring policy.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function rules( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$rules = $this->policy->rules();

		return ApiResponse::ok(
			array(
				'rules'      => $rules->toArray(),
				'bands'      => $this->policy->thresholds(),
				'alerts'     => $this->policy->alerts(),
				'ceiling'    => $rules->ceiling(),
				// The editor says "these are suggestions" until somebody has
				// saved, which is the difference between a screen showing a
				// starting point and one showing a policy that was chosen.
				'customised' => $this->policy->isCustomised(),
				'kinds'      => $this->vocabulary(),
				'max_rules'  => RuleSet::MAX_RULES,
			)
		);
	}

	/**
	 * Replace the scoring policy.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function saveRules( WP_REST_Request $request ): WP_REST_Response {
		$rules = $request->get_param( 'rules' );

		if ( is_array( $rules ) ) {
			// Normalised through the same value objects that evaluate them,
			// so what is stored is exactly what will run. Cleaning a second
			// way here is how a rule that reads correctly in the editor
			// comes to behave differently on the site.
			$set = RuleSet::fromArray( $this->cleanRules( $rules ) );

			$this->policy->saveRules( $set );

			$this->audit->record(
				PipelineService::RULES_UPDATED,
				array( 'rules' => count( $set->rules ) )
			);
		}

		$bands = $request->get_param( 'bands' );

		if ( is_array( $bands ) ) {
			$thresholds = array();

			foreach ( array( 'warm', 'hot', 'qualified' ) as $band ) {
				if ( isset( $bands[ $band ] ) && is_numeric( $bands[ $band ] ) ) {
					$thresholds[ $band ] = (int) $bands[ $band ];
				}
			}

			$this->policy->saveThresholds( $thresholds );
		}

		$alerts = $request->get_param( 'alerts' );

		if ( is_array( $alerts ) ) {
			$this->policy->saveAlerts( $this->cleanAlerts( $alerts ) );

			$this->audit->record(
				PipelineService::ALERTS_UPDATED,
				array( 'enabled' => (bool) ( $alerts['enabled'] ?? false ) )
			);
		}

		return $this->rules( $request );
	}

	/**
	 * Sanitise the rule list, key by key.
	 *
	 * @param array<mixed> $rules Raw rules.
	 * @return array<int, array<string, mixed>>
	 */
	private function cleanRules( array $rules ): array {
		$clean = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$clean[] = array(
				'id'       => sanitize_key( (string) ( $rule['id'] ?? '' ) ),
				'label'    => sanitize_text_field( (string) ( $rule['label'] ?? '' ) ),
				'kind'     => sanitize_key( (string) ( $rule['kind'] ?? '' ) ),
				'operator' => sanitize_key( (string) ( $rule['operator'] ?? '' ) ),
				'target'   => sanitize_text_field( (string) ( $rule['target'] ?? '' ) ),
				'value'    => sanitize_text_field( (string) ( $rule['value'] ?? '' ) ),
				'points'   => isset( $rule['points'] ) && is_numeric( $rule['points'] ) ? (int) $rule['points'] : 0,
				'enabled'  => (bool) ( $rule['enabled'] ?? true ),
				'once'     => (bool) ( $rule['once'] ?? true ),
			);
		}

		return $clean;
	}

	/**
	 * Sanitise the alert settings.
	 *
	 * @param array<mixed> $alerts Raw settings.
	 * @return array<string, mixed>
	 */
	private function cleanAlerts( array $alerts ): array {
		$emails  = array();
		$webhook = isset( $alerts['slack_webhook'] ) ? esc_url_raw( (string) $alerts['slack_webhook'] ) : '';

		foreach ( is_array( $alerts['emails'] ?? null ) ? $alerts['emails'] : array() as $email ) {
			$clean = sanitize_email( (string) $email );

			if ( '' !== $clean && is_email( $clean ) ) {
				$emails[] = $clean;
			}
		}

		return array(
			'enabled'       => (bool) ( $alerts['enabled'] ?? false ),
			'score'         => isset( $alerts['score'] ) && is_numeric( $alerts['score'] )
				? (int) $alerts['score']
				: ScoringPolicy::DEFAULT_ALERT_SCORE,
			'emails'        => array_values( array_unique( $emails ) ),
			// Only https. A Slack webhook is https; anything else is either
			// a typo or somebody pointing the site at plaintext, and neither
			// is worth honouring on an endpoint that posts customer data.
			'slack_webhook' => str_starts_with( $webhook, 'https://' ) ? $webhook : '',
		);
	}

	/**
	 * The vocabulary the rule editor builds its dropdowns from.
	 *
	 * Served rather than duplicated in TypeScript. Two lists of operators
	 * that drift apart produce a rule the editor offers and the engine
	 * does not understand.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function vocabulary(): array {
		$kinds = array();

		foreach ( RuleKind::cases() as $kind ) {
			$operators = array();

			foreach ( $this->operatorsFor( $kind ) as $operator ) {
				$operators[] = array(
					'value'       => $operator->value,
					'label'       => $operator->label(),
					'needs_value' => $operator->needsValue(),
				);
			}

			$kinds[] = array(
				'value'     => $kind->value,
				'label'     => $kind->label(),
				'operators' => $operators,
				'targets'   => $this->targetsFor( $kind ),
			);
		}

		return $kinds;
	}

	/**
	 * Operators that make sense for a rule kind.
	 *
	 * @param RuleKind $kind Rule kind.
	 * @return array<int, RuleOperator>
	 */
	private function operatorsFor( RuleKind $kind ): array {
		return match ( $kind ) {
			RuleKind::Field   => array(
				RuleOperator::NotEmpty,
				RuleOperator::IsEmpty,
				RuleOperator::Equals,
				RuleOperator::NotEquals,
				RuleOperator::Contains,
				RuleOperator::Matches,
				RuleOperator::GreaterOrEqual,
				RuleOperator::LessOrEqual,
				RuleOperator::IsBusiness,
			),
			RuleKind::Keyword => array( RuleOperator::Contains ),
			default           => array( RuleOperator::GreaterOrEqual, RuleOperator::LessOrEqual ),
		};
	}

	/**
	 * Targets a rule kind can address.
	 *
	 * @param RuleKind $kind Rule kind.
	 * @return array<int, string>
	 */
	private function targetsFor( RuleKind $kind ): array {
		return match ( $kind ) {
			RuleKind::Field      => array( 'email', 'phone', 'company', 'job_title', 'website', 'first_name', 'last_name' ),
			RuleKind::Engagement => array( 'messages', 'answers', 'page_views', 'conversations' ),
			default              => array(),
		};
	}

	/**
	 * Read the writable stage fields.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<string, mixed>
	 */
	private function stageInput( WP_REST_Request $request ): array {
		$input = array();

		foreach ( array( 'name', 'color' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = (string) $request->get_param( $key );
			}
		}

		foreach ( array( 'is_won', 'is_lost' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = (bool) $request->get_param( $key );
			}
		}

		return $input;
	}

	/**
	 * Arguments accepted when writing a stage.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function stageArgs(): array {
		return array(
			'name'    => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'color'   => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			),
			'is_won'  => array(
				'type'     => 'boolean',
				'required' => false,
			),
			'is_lost' => array(
				'type'     => 'boolean',
				'required' => false,
			),
		);
	}
}
