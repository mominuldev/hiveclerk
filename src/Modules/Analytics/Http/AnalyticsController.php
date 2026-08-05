<?php
/**
 * Analytics endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Analytics\Http;

use DateTimeZone;
use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Analytics\DailyMetrics;
use Hiveclerk\Domain\Analytics\FunnelStep;
use Hiveclerk\Domain\Analytics\Kpi;
use Hiveclerk\Domain\Shared\DateRange;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Analytics\Services\AlertService;
use Hiveclerk\Modules\Analytics\Services\AnalyticsService;
use Hiveclerk\Modules\Analytics\Services\ReportExporter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The dashboard and the analytics area (FR-ANL-01, 02, 05, 06, 07).
 *
 * Gated on `view_conversations` rather than `manage_settings`, as the
 * cost endpoint already is. Spend and performance are operational
 * information a supervisor needs, and putting them behind the capability
 * that holds the API key means the person answerable for the bill cannot
 * see it.
 */
final class AnalyticsController extends AbstractController {

	/**
	 * Default window when the caller does not choose one.
	 */
	private const DEFAULT_DAYS = 30;

	/**
	 * Construct.
	 *
	 * @param AnalyticsService         $analytics Report assembly.
	 * @param AlertService             $alerts    Needs-attention queue.
	 * @param ReportExporter           $exporter  CSV.
	 * @param AgentRepositoryInterface $agents    Clerk storage.
	 * @param ClockInterface           $clock     Clock.
	 */
	public function __construct(
		private readonly AnalyticsService $analytics,
		private readonly AlertService $alerts,
		private readonly ReportExporter $exporter,
		private readonly AgentRepositoryInterface $agents,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		$read = $this->requires( Capabilities::VIEW_CONVERSATIONS );

		foreach ( array( 'overview', 'agents', 'funnel', 'topics' ) as $report ) {
			register_rest_route(
				self::NAMESPACE,
				'/admin/analytics/' . $report,
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, $report ),
					'permission_callback' => $read,
					'args'                => $this->rangeArgs(),
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/admin/analytics/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $read,
				'args'                => array_merge(
					$this->rangeArgs(),
					array(
						'report' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'overview',
							'enum'              => ReportExporter::REPORTS,
							'sanitize_callback' => 'sanitize_key',
							// WordPress only enforces `enum` when a
							// validate_callback is registered alongside
							// it. Without this the parameter is
							// decoration, which is the bug Sprint 7
							// found on /public/events.
							'validate_callback' => 'rest_validate_request_arg',
						),
					)
				),
			)
		);
	}

	/**
	 * Dashboard payload: KPIs, series, funnel finding, top questions, alerts.
	 *
	 * One endpoint rather than five, because the dashboard renders all of
	 * it at once and five round trips is five chances for the screen to
	 * assemble itself in front of the reader.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function overview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$range = $this->range( $request );

		if ( $range instanceof WP_Error ) {
			return $range;
		}

		$agentId = $this->agentId( $request );

		if ( $agentId instanceof WP_Error ) {
			return $agentId;
		}

		$compare = false !== $request->get_param( 'compare' );
		$series  = $this->analytics->series( $range, $agentId );
		$totals  = $this->analytics->totals( $range, $agentId );

		return ApiResponse::ok(
			array(
				'range'      => $range->toArray(),
				'compare'    => $compare ? $range->previous()->toArray() : null,
				'kpis'       => array_map(
					static fn ( Kpi $kpi ): array => $kpi->jsonSerialize(),
					$this->analytics->kpis( $range, $compare, $agentId )
				),
				'series'     => array_map(
					static fn ( DailyMetrics $day ): array => $day->toArray(),
					$series
				),
				'totals'     => array_merge(
					$totals->toArray(),
					array(
						'deflection_rate'       => $totals->deflectionRate(),
						'cost_per_conversation' => $totals->costPerConversation(),
					)
				),
				'top_topics' => $this->analytics->topics( $range, 5, $agentId )['topics'],
				'alerts'     => array_map(
					static fn ( $alert ): array => $alert->jsonSerialize(),
					$this->alerts->pending()
				),
			)
		);
	}

	/**
	 * Per-clerk comparison.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function agents( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$range = $this->range( $request );

		if ( $range instanceof WP_Error ) {
			return $range;
		}

		return ApiResponse::ok(
			array(
				'range'  => $range->toArray(),
				'agents' => $this->analytics->byAgent( $range ),
			)
		);
	}

	/**
	 * The lead funnel and the sentence under it.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function funnel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$range = $this->range( $request );

		if ( $range instanceof WP_Error ) {
			return $range;
		}

		$agentId = $this->agentId( $request );

		if ( $agentId instanceof WP_Error ) {
			return $agentId;
		}

		$steps = $this->analytics->funnel( $range, $agentId );

		return ApiResponse::ok(
			array(
				'range'   => $range->toArray(),
				'steps'   => array_map(
					static fn ( FunnelStep $step ): array => $step->jsonSerialize(),
					$steps
				),
				'finding' => $this->analytics->funnelFinding( $steps ),
			)
		);
	}

	/**
	 * The questions visitors arrive with.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function topics( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$range = $this->range( $request );

		if ( $range instanceof WP_Error ) {
			return $range;
		}

		$agentId = $this->agentId( $request );

		if ( $agentId instanceof WP_Error ) {
			return $agentId;
		}

		$result = $this->analytics->topics( $range, 20, $agentId );

		return ApiResponse::ok(
			array(
				'range'   => $range->toArray(),
				'topics'  => $result['topics'],
				// Said out loud rather than implied. A list built from a
				// slice of a busy month is useful; a list that claims to
				// have counted everything and did not is not.
				'sampled' => $result['sampled'],
				'of'      => $result['of'],
			)
		);
	}

	/**
	 * Any report as CSV (FR-ANL-07).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function export( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$range = $this->range( $request );

		if ( $range instanceof WP_Error ) {
			return $range;
		}

		$agentId = $this->agentId( $request );

		if ( $agentId instanceof WP_Error ) {
			return $agentId;
		}

		$report = (string) ( $request->get_param( 'report' ) ?? 'overview' );

		if ( ! in_array( $report, ReportExporter::REPORTS, true ) ) {
			$report = 'overview';
		}

		return ApiResponse::ok( $this->exporter->export( $report, $range, $agentId ) );
	}

	/**
	 * Arguments every report route accepts.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function rangeArgs(): array {
		return array(
			'from'    => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'to'      => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'agent'   => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'compare' => array(
				'type'     => 'boolean',
				'required' => false,
				'default'  => true,
			),
		);
	}

	/**
	 * The requested range, defaulting to the last thirty days.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return DateRange|WP_Error
	 */
	private function range( WP_REST_Request $request ): DateRange|WP_Error {
		$today = $this->clock->now()->setTimezone( new DateTimeZone( 'UTC' ) );
		$from  = $this->stringParam( $request, 'from' );
		$to    = $this->stringParam( $request, 'to' );

		if ( null === $from && null === $to ) {
			return DateRange::lastDays( $today, self::DEFAULT_DAYS );
		}

		$from ??= $today->modify( '-' . ( self::DEFAULT_DAYS - 1 ) . ' days' )->format( 'Y-m-d' );
		$to   ??= $today->format( 'Y-m-d' );

		if ( ! DateRange::isDate( $from ) || ! DateRange::isDate( $to ) ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				'Dates must be in YYYY-MM-DD form.',
				422
			);
		}

		if ( $from > $to ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				'The start date must not be after the end date.',
				422
			);
		}

		$range = new DateRange( $from, $to );

		if ( $range->days() > DateRange::MAX_DAYS ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				sprintf( 'Ranges are limited to %d days.', DateRange::MAX_DAYS ),
				422
			);
		}

		return $range;
	}

	/**
	 * The clerk filter, resolved from a uuid.
	 *
	 * A uuid rather than a numeric id, because the SPA never sees storage
	 * ids and an endpoint that accepted one would be the only place it
	 * had to.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return int|null|WP_Error
	 */
	private function agentId( WP_REST_Request $request ): int|null|WP_Error {
		$uuid = $this->stringParam( $request, 'agent' );

		if ( null === $uuid || 'all' === $uuid ) {
			return null;
		}

		if ( ! Uuid::isValid( $uuid ) ) {
			return ApiResponse::error( ErrorCode::VALIDATION_FAILED, 'That is not a valid clerk id.', 422 );
		}

		$agent = $this->agents->findByUuid( new Uuid( $uuid ) );

		if ( null === $agent || null === $agent->id ) {
			return ApiResponse::error( ErrorCode::NOT_FOUND, 'That clerk does not exist.', 404 );
		}

		return $agent->id;
	}
}
