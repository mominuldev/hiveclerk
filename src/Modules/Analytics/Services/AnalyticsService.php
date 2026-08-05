<?php
/**
 * Report assembly.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Analytics\Services;

use DateTimeZone;
use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Analytics\AnalyticsRepositoryInterface;
use Hiveclerk\Domain\Analytics\DailyMetrics;
use Hiveclerk\Domain\Analytics\FunnelStep;
use Hiveclerk\Domain\Analytics\Kpi;
use Hiveclerk\Domain\Analytics\ReportSourceInterface;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Lead\ScoreBand;
use Hiveclerk\Domain\Shared\DateRange;
use Hiveclerk\Domain\Shared\Pagination;
use Hiveclerk\Modules\Analytics\Support\TopicGrouper;

/**
 * Reads the rollup table and turns it into the screens.
 *
 * Nothing here touches a message. Every series comes from
 * `hvc_analytics_daily`, with one exception that is the whole reason the
 * class exists: today, which has not been rolled up and is counted live
 * before being merged over the top. A dashboard whose "last 30 days"
 * silently stopped at yesterday would be wrong on exactly the day
 * somebody is watching a launch.
 */
final class AnalyticsService {

	/**
	 * Conversations sampled when grouping topics.
	 *
	 * A top-questions list built from two thousand conversations is the
	 * same list as one built from fifty thousand, and the response says
	 * out loud when it was sampled rather than implying it counted
	 * everything.
	 */
	private const TOPIC_SAMPLE = 2000;

	/**
	 * Construct.
	 *
	 * @param AnalyticsRepositoryInterface $rollups  Stored daily figures.
	 * @param ReportSourceInterface        $reports  Funnel and topic reads.
	 * @param AgentRepositoryInterface     $agents   Clerk storage.
	 * @param RollupService                $rollup   Live counting for today.
	 * @param SettingsRepository           $settings Settings.
	 * @param ClockInterface               $clock    Clock.
	 */
	public function __construct(
		private readonly AnalyticsRepositoryInterface $rollups,
		private readonly ReportSourceInterface $reports,
		private readonly AgentRepositoryInterface $agents,
		private readonly RollupService $rollup,
		private readonly SettingsRepository $settings,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * A day-by-day series over a range, gaps filled.
	 *
	 * @param DateRange $range   Span.
	 * @param int|null  $agentId Clerk, or null for site-wide.
	 * @return array<int, DailyMetrics> One entry per day, oldest first.
	 */
	public function series( DateRange $range, ?int $agentId = null ): array {
		$stored = array();

		foreach ( $this->rollups->between( $range, $agentId ) as $metrics ) {
			$stored[ $metrics->date ] = $metrics;
		}

		$today = $this->today();
		$days  = array();

		foreach ( $range->eachDay() as $day ) {
			if ( $day === $today ) {
				// Counted live. Any stored row for today is a partial
				// snapshot from an earlier run of the same day, and adding
				// it to a fresh count would double what has happened so
				// far — so it is replaced, never merged.
				$days[] = $this->rollup->today( $agentId );

				continue;
			}

			$days[] = $stored[ $day ] ?? DailyMetrics::empty( $day, $agentId );
		}

		return $days;
	}

	/**
	 * Totals over a range.
	 *
	 * @param DateRange $range   Span.
	 * @param int|null  $agentId Clerk.
	 * @return DailyMetrics Dated with the last day of the range.
	 */
	public function totals( DateRange $range, ?int $agentId = null ): DailyMetrics {
		$total = DailyMetrics::empty( $range->to, $agentId );

		foreach ( $this->series( $range, $agentId ) as $day ) {
			$total = $total->plus( $day );
		}

		return $total;
	}

	/**
	 * The four dashboard KPI cards.
	 *
	 * Qualified leads leads the row because it is the PRD's North Star and
	 * the only outcome metric on the card. Conversation count can rise
	 * while the product fails; qualified conversations cannot.
	 *
	 * @param DateRange $range   Span.
	 * @param bool      $compare Whether to measure the previous period too.
	 * @param int|null  $agentId Clerk.
	 * @return array<int, Kpi>
	 */
	public function kpis( DateRange $range, bool $compare = true, ?int $agentId = null ): array {
		$series   = $this->series( $range, $agentId );
		$current  = $this->fold( $series );
		$previous = $compare ? $this->fold( $this->series( $range->previous(), $agentId ) ) : null;

		return array(
			new Kpi(
				'leads_qualified',
				'Qualified',
				(float) $current->leadsQualified,
				null === $previous ? null : (float) $previous->leadsQualified,
				array_map( static fn ( DailyMetrics $d ): float => (float) $d->leadsQualified, $series )
			),
			new Kpi(
				'conversations',
				'Conversations',
				(float) $current->conversations,
				null === $previous ? null : (float) $previous->conversations,
				array_map( static fn ( DailyMetrics $d ): float => (float) $d->conversations, $series )
			),
			new Kpi(
				'leads_captured',
				'Leads captured',
				(float) $current->leadsCaptured,
				null === $previous ? null : (float) $previous->leadsCaptured,
				array_map( static fn ( DailyMetrics $d ): float => (float) $d->leadsCaptured, $series )
			),
			new Kpi(
				'cost',
				'Spend',
				$current->cost,
				null === $previous ? null : $previous->cost,
				array_map( static fn ( DailyMetrics $d ): float => $d->cost, $series ),
				'currency',
				// The one card where up is not good news.
				false
			),
		);
	}

	/**
	 * The lead funnel, with each rung's conversion from the one above.
	 *
	 * @param DateRange $range   Span.
	 * @param int|null  $agentId Clerk.
	 * @return array<int, FunnelStep>
	 */
	public function funnel( DateRange $range, ?int $agentId = null ): array {
		$counts = $this->reports->funnel( $range, $this->qualifiedScore(), $agentId );

		$labels = array(
			'conversations' => 'Conversations',
			'engaged'       => 'Engaged (3+ messages)',
			'captured'      => 'Captured',
			'qualified'     => 'Qualified',
			'won'           => 'Won',
		);

		$steps    = array();
		$previous = 0;

		foreach ( $labels as $key => $label ) {
			$count    = (int) ( $counts[ $key ] ?? 0 );
			$steps[]  = new FunnelStep( $key, $label, $count, $previous );
			$previous = $count;
		}

		return $steps;
	}

	/**
	 * The sentence that goes under the funnel.
	 *
	 * D11 §10 is explicit that every chart carries a written finding, and
	 * the finding is the deliverable — a funnel that only draws bars
	 * leaves the reader to work out which rung to fix, which is the whole
	 * job. Returns null when there is nothing honest to say.
	 *
	 * @param array<int, FunnelStep> $steps Funnel.
	 * @return array{text: string, step: string}|null
	 */
	public function funnelFinding( array $steps ): ?array {
		$worst = null;

		foreach ( $steps as $step ) {
			if ( null === $step->rate() || 0 === $step->dropOff() ) {
				continue;
			}

			if ( null === $worst || $step->dropOff() > $worst->dropOff() ) {
				$worst = $step;
			}
		}

		if ( null === $worst ) {
			return null;
		}

		return array(
			'step' => $worst->key,
			'text' => sprintf(
				/* translators: 1: number of visitors lost, 2: funnel step name. */
				__( '%1$s visitors reached the previous step and did not become %2$s.', 'hiveclerk' ),
				number_format_i18n( $worst->dropOff() ),
				strtolower( $worst->label )
			),
		);
	}

	/**
	 * Per-clerk performance over a range.
	 *
	 * @param DateRange $range Span.
	 * @return array<int, array<string, mixed>> One entry per clerk, busiest first.
	 */
	public function byAgent( DateRange $range ): array {
		$grouped = $this->rollups->byAgent( $range );
		$rows    = array();

		// Today has not been rolled up and never will be, so it is merged
		// here for the same reason series() merges it: a comparison that
		// silently stopped at yesterday would read zero for every clerk
		// on the morning a site goes live.
		$today = $range->contains( $this->today() ) ? $this->rollup->todayByAgent() : array();

		foreach ( $this->agents->paginate( new Pagination( 1, 100 ) ) as $agent ) {
			if ( null === $agent->id ) {
				continue;
			}

			$days = array_values(
				array_filter(
					$grouped[ $agent->id ] ?? array(),
					fn ( DailyMetrics $day ): bool => $day->date !== $this->today()
				)
			);

			if ( isset( $today[ $agent->id ] ) ) {
				$days[] = $today[ $agent->id ];
			}

			$total = $this->fold( $days );

			$rows[] = array(
				'agent'           => $this->agentSummary( $agent ),
				'conversations'   => $total->conversations,
				'messages'        => $total->messages,
				'leads_captured'  => $total->leadsCaptured,
				'leads_qualified' => $total->leadsQualified,
				'handoffs'        => $total->handoffs,
				'deflection_rate' => $total->deflectionRate(),
				'cost'            => round( $total->cost, 4 ),
				'avg_latency_ms'  => $total->avgLatencyMs,
				'positive'        => $total->positiveRatings,
				'negative'        => $total->negativeRatings,
			);
		}

		usort(
			$rows,
			static fn ( array $a, array $b ): int => $b['conversations'] <=> $a['conversations']
		);

		return $rows;
	}

	/**
	 * The questions visitors arrive with, grouped and counted.
	 *
	 * @param DateRange $range   Span.
	 * @param int       $limit   How many to return.
	 * @param int|null  $agentId Clerk.
	 * @return array{topics: array<int, array<string, mixed>>, sampled: bool, of: int}
	 */
	public function topics( DateRange $range, int $limit = 10, ?int $agentId = null ): array {
		$questions = $this->reports->openingQuestions( $range, self::TOPIC_SAMPLE, $agentId );
		$total     = $this->reports->conversationCount( $range, $agentId );

		$groups = array();

		foreach ( $questions as $question ) {
			$key = TopicGrouper::key( $question );

			if ( '' === $key ) {
				continue;
			}

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'label' => TopicGrouper::label( $question ),
					'count' => 0,
				);
			}

			++$groups[ $key ]['count'];
		}

		uasort(
			$groups,
			static fn ( array $a, array $b ): int => $b['count'] <=> $a['count']
		);

		return array(
			'topics'  => array_values( array_slice( $groups, 0, max( 1, $limit ) ) ),
			'sampled' => $total > count( $questions ),
			'of'      => $total,
		);
	}

	/**
	 * Today, as a UTC calendar day.
	 *
	 * @return string
	 */
	private function today(): string {
		return $this->clock->now()->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d' );
	}

	/**
	 * Add a list of days together.
	 *
	 * @param array<int, DailyMetrics> $days Days.
	 * @return DailyMetrics
	 */
	private function fold( array $days ): DailyMetrics {
		if ( array() === $days ) {
			return DailyMetrics::empty( '1970-01-01' );
		}

		$total = DailyMetrics::empty( $days[ array_key_last( $days ) ]->date );

		foreach ( $days as $day ) {
			$total = $total->plus( $day );
		}

		return $total;
	}

	/**
	 * The clerk fields a report row carries.
	 *
	 * @param Agent $agent Clerk.
	 * @return array<string, mixed>
	 */
	private function agentSummary( Agent $agent ): array {
		return array(
			'id'     => $agent->id,
			'uuid'   => $agent->uuid->value,
			'name'   => $agent->name,
			'status' => $agent->status->value,
		);
	}

	/**
	 * The score at which a lead counts as qualified.
	 *
	 * @return int
	 */
	private function qualifiedScore(): int {
		$bands = $this->settings->get( 'leads.bands' );
		$value = is_array( $bands ) ? ( $bands['qualified'] ?? null ) : null;

		return is_numeric( $value ) ? max( 0, (int) $value ) : ScoreBand::DEFAULTS['qualified'];
	}
}
