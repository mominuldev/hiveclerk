<?php
/**
 * Report export.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Analytics\Services;

use Hiveclerk\Core\Support\Csv;
use Hiveclerk\Domain\Analytics\DailyMetrics;
use Hiveclerk\Domain\Analytics\FunnelStep;
use Hiveclerk\Domain\Shared\DateRange;

/**
 * Any report as CSV (FR-ANL-07).
 *
 * Four reports, one file each, because a spreadsheet with four tables
 * stacked in one sheet is a spreadsheet nobody can pivot. The row cap
 * that the lead export needs does not apply here: every one of these is
 * already bounded — a year of days, a roster, ten topics, five funnel
 * rungs.
 *
 * Numbers go out unformatted. `14.82` is a number a spreadsheet can add
 * up; `$14.82` is a string, and the customer discovers that after they
 * have built the pivot table.
 */
final class ReportExporter {

	/**
	 * Reports this exporter knows how to build.
	 */
	public const REPORTS = array( 'overview', 'agents', 'funnel', 'topics' );

	/**
	 * Construct.
	 *
	 * @param AnalyticsService $analytics Report assembly.
	 */
	public function __construct(
		private readonly AnalyticsService $analytics
	) {
	}

	/**
	 * Build one report.
	 *
	 * @param string    $report  One of self::REPORTS.
	 * @param DateRange $range   Span.
	 * @param int|null  $agentId Clerk.
	 * @return array{filename: string, csv: string, rows: int}
	 */
	public function export( string $report, DateRange $range, ?int $agentId = null ): array {
		$lines = match ( $report ) {
			'agents' => $this->agents( $range ),
			'funnel' => $this->funnel( $range, $agentId ),
			'topics' => $this->topics( $range, $agentId ),
			default  => $this->overview( $range, $agentId ),
		};

		return array(
			'filename' => sprintf( 'hiveclerk-%s-%s-to-%s.csv', $report, $range->from, $range->to ),
			'csv'      => implode( "\n", $lines ),
			// The header is not a row of data, and a caller reporting
			// "12 rows exported" for eleven days plus a heading is the
			// kind of off-by-one nobody ever checks.
			'rows'     => max( 0, count( $lines ) - 1 ),
		);
	}

	/**
	 * Day-by-day figures.
	 *
	 * @param DateRange $range   Span.
	 * @param int|null  $agentId Clerk.
	 * @return array<int, string>
	 */
	private function overview( DateRange $range, ?int $agentId ): array {
		$lines = array(
			Csv::line(
				array(
					'date',
					'conversations',
					'messages',
					'unique_visitors',
					'leads_captured',
					'leads_qualified',
					'handoffs',
					'resolved_by_ai',
					'positive_ratings',
					'negative_ratings',
					'unanswered',
					'tokens_in',
					'tokens_out',
					'cost_usd',
					'avg_latency_ms',
				)
			),
		);

		foreach ( $this->analytics->series( $range, $agentId ) as $day ) {
			$lines[] = Csv::line( $this->overviewRow( $day ) );
		}

		return $lines;
	}

	/**
	 * One day as cells.
	 *
	 * @param DailyMetrics $day Day.
	 * @return array<int, string>
	 */
	private function overviewRow( DailyMetrics $day ): array {
		return array(
			$day->date,
			(string) $day->conversations,
			(string) $day->messages,
			(string) $day->uniqueVisitors,
			(string) $day->leadsCaptured,
			(string) $day->leadsQualified,
			(string) $day->handoffs,
			(string) $day->resolvedByAi,
			(string) $day->positiveRatings,
			(string) $day->negativeRatings,
			(string) $day->unanswered,
			(string) $day->tokensIn,
			(string) $day->tokensOut,
			number_format( $day->cost, 6, '.', '' ),
			null === $day->avgLatencyMs ? '' : (string) $day->avgLatencyMs,
		);
	}

	/**
	 * Per-clerk comparison.
	 *
	 * @param DateRange $range Span.
	 * @return array<int, string>
	 */
	private function agents( DateRange $range ): array {
		$lines = array(
			Csv::line(
				array(
					'clerk',
					'status',
					'conversations',
					'messages',
					'leads_captured',
					'leads_qualified',
					'handoffs',
					'deflection_rate',
					'cost_usd',
					'avg_latency_ms',
					'positive_ratings',
					'negative_ratings',
				)
			),
		);

		foreach ( $this->analytics->byAgent( $range ) as $row ) {
			$lines[] = Csv::line(
				array(
					(string) $row['agent']['name'],
					(string) $row['agent']['status'],
					(string) $row['conversations'],
					(string) $row['messages'],
					(string) $row['leads_captured'],
					(string) $row['leads_qualified'],
					(string) $row['handoffs'],
					// Blank rather than 0 when the clerk held no
					// conversations: a deflection rate of zero is a
					// judgement about a clerk nobody spoke to.
					null === $row['deflection_rate']
						? ''
						: number_format( (float) $row['deflection_rate'], 4, '.', '' ),
					number_format( (float) $row['cost'], 6, '.', '' ),
					null === $row['avg_latency_ms'] ? '' : (string) $row['avg_latency_ms'],
					(string) $row['positive'],
					(string) $row['negative'],
				)
			);
		}

		return $lines;
	}

	/**
	 * The lead funnel.
	 *
	 * @param DateRange $range   Span.
	 * @param int|null  $agentId Clerk.
	 * @return array<int, string>
	 */
	private function funnel( DateRange $range, ?int $agentId ): array {
		$lines = array( Csv::line( array( 'step', 'count', 'conversion_from_previous', 'drop_off' ) ) );

		foreach ( $this->analytics->funnel( $range, $agentId ) as $step ) {
			if ( ! $step instanceof FunnelStep ) {
				continue;
			}

			$rate = $step->rate();

			$lines[] = Csv::line(
				array(
					$step->label,
					(string) $step->count,
					null === $rate ? '' : number_format( $rate, 4, '.', '' ),
					(string) $step->dropOff(),
				)
			);
		}

		return $lines;
	}

	/**
	 * Top questions.
	 *
	 * @param DateRange $range   Span.
	 * @param int|null  $agentId Clerk.
	 * @return array<int, string>
	 */
	private function topics( DateRange $range, ?int $agentId ): array {
		$result = $this->analytics->topics( $range, 50, $agentId );
		$lines  = array( Csv::line( array( 'question', 'asked' ) ) );

		foreach ( $result['topics'] as $topic ) {
			$lines[] = Csv::line( array( (string) $topic['label'], (string) $topic['count'] ) );
		}

		return $lines;
	}
}
