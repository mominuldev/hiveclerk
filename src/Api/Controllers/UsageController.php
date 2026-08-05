<?php
/**
 * Spend reporting endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Api\Controllers;

use DateTimeImmutable;
use Hiveclerk\Ai\PricingTable;
use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Usage\UsageRepositoryInterface;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Reports what the model providers have cost.
 *
 * Requires view_conversations rather than manage_settings. Spend is
 * operational information a supervisor needs, and gating it behind the
 * same capability as the API key would mean the person answering for the
 * bill cannot see it.
 */
final class UsageController extends AbstractController {

	/**
	 * Longest range that can be requested.
	 *
	 * Bounded because the aggregate scans an index range, and an
	 * unbounded date span is the request that turns a fast query into a
	 * table scan on a site with two years of history.
	 */
	private const MAX_DAYS = 366;

	/**
	 * Construct.
	 *
	 * @param UsageRepositoryInterface $usage Usage storage.
	 * @param ClockInterface           $clock Clock.
	 */
	public function __construct(
		private readonly UsageRepositoryInterface $usage,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/analytics/costs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'costs' ),
				'permission_callback' => $this->requires( Capabilities::VIEW_CONVERSATIONS ),
				'args'                => array(
					'from' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'to'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Spend over a date range.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function costs( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$today = $this->clock->now();
		$to    = self::date( $this->stringParam( $request, 'to' ), $today->format( 'Y-m-d' ) );
		$from  = self::date(
			$this->stringParam( $request, 'from' ),
			$today->modify( '-29 days' )->format( 'Y-m-d' )
		);

		if ( null === $from || null === $to ) {
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

		if ( self::daysBetween( $from, $to ) > self::MAX_DAYS ) {
			return ApiResponse::error(
				ErrorCode::VALIDATION_FAILED,
				sprintf( 'Ranges are limited to %d days.', self::MAX_DAYS ),
				422
			);
		}

		$total = $this->usage->summarise( $from, $to );

		return ApiResponse::ok(
			array(
				'range'    => array(
					'from' => $from,
					'to'   => $to,
				),
				'total'    => $total->jsonSerialize(),
				'by_model' => array_map(
					static fn ( $summary ): array => $summary->jsonSerialize(),
					$this->usage->byModel( $from, $to )
				),
				'daily'    => array_map(
					static fn ( $summary ): array => $summary->jsonSerialize(),
					$this->usage->daily( $from, $to )
				),
			),
			array(
				'currency'      => 'USD',
				'pricing_as_of' => PricingTable::AS_OF,
			)
		);
	}

	/**
	 * Validate a Y-m-d date, falling back when absent.
	 *
	 * Round-tripped through the parser rather than pattern-matched, so
	 * 2026-02-30 is rejected rather than silently becoming 2 March.
	 *
	 * @param string|null $value    Supplied value.
	 * @param string      $fallback Default when nothing was supplied.
	 * @return string|null Null when supplied but invalid.
	 */
	private static function date( ?string $value, string $fallback ): ?string {
		if ( null === $value ) {
			return $fallback;
		}

		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d', $value );

		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $value ? $value : null;
	}

	/**
	 * Whole days between two dates, inclusive.
	 *
	 * @param string $from Start date.
	 * @param string $to   End date.
	 * @return int
	 */
	private static function daysBetween( string $from, string $to ): int {
		$start = new DateTimeImmutable( $from );
		$end   = new DateTimeImmutable( $to );

		return (int) $start->diff( $end )->days + 1;
	}
}
