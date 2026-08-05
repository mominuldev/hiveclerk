import { Card, StatRow } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Skeleton } from '@/components/ui/Skeleton';
import { TrendChart } from '@/components/charts/TrendChart';
import { KpiCard } from '@/components/analytics/KpiCard';
import { useOverview } from '@/api/queries/useAnalytics';
import { useReportFilters } from './AnalyticsShell';
import { formatCompact, formatCost } from '@/lib/format';

/**
 * The overview tab: the same KPIs as the dashboard, plus the totals
 * behind them.
 *
 * The dashboard answers "is this working"; this answers "show me the
 * numbers". Repeating the KPI row is deliberate — an operator who
 * arrived here from a card wants to see the card they clicked.
 */
export function AnalyticsOverview() {
  const filters = useReportFilters();
  const { data, isPending, isError, error, refetch } = useOverview(filters);

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  if (isPending) {
    return <Skeleton className="h-[420px] w-full rounded-xl" />;
  }

  const totals = data.totals;

  if (0 === totals.conversations && 0 === totals.messages) {
    return (
      <Card>
        <EmptyState
          title="Nothing to report yet."
          description="Once a clerk is on duty and visitors start talking, everything on this screen fills in on its own."
        />
      </Card>
    );
  }

  return (
    <div className="space-y-5">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {data.kpis.map((kpi, index) => (
          <KpiCard key={kpi.key} kpi={kpi} feature={index === 0} />
        ))}
      </div>

      <Card eyebrow="Volume" title="Conversations per day">
        <TrendChart
          label="Conversations"
          points={data.series.map((day) => ({
            date: day.date,
            value: day.conversations,
          }))}
        />
      </Card>

      <div className="grid gap-5 lg:grid-cols-2">
        <Card eyebrow="Totals" title="Everything in this period">
          <dl>
            <StatRow
              label="Conversations"
              value={totals.conversations.toLocaleString()}
              emphasis
            />
            <StatRow label="Messages" value={totals.messages.toLocaleString()} />
            <StatRow
              label="Unique visitors"
              value={totals.unique_visitors.toLocaleString()}
            />
            <StatRow
              label="Leads captured"
              value={totals.leads_captured.toLocaleString()}
            />
            <StatRow
              label="Leads qualified"
              value={totals.leads_qualified.toLocaleString()}
              emphasis
            />
            <StatRow label="Handed to a person" value={totals.handoffs.toLocaleString()} />
            <StatRow
              label="Answered without a person"
              value={
                totals.deflection_rate === null
                  ? '—'
                  : `${Math.round(totals.deflection_rate * 100)}%`
              }
            />
          </dl>
        </Card>

        <Card eyebrow="Quality" title="How the answers landed">
          <dl>
            <StatRow
              label="Rated helpful"
              value={totals.positive_ratings.toLocaleString()}
            />
            <StatRow
              label="Rated unhelpful"
              value={totals.negative_ratings.toLocaleString()}
            />
            <StatRow
              label="Questions with no confident match"
              value={totals.unanswered.toLocaleString()}
              emphasis
            />
            <StatRow
              label="Median reply latency"
              value={
                totals.avg_latency_ms === null
                  ? '—'
                  : `${(totals.avg_latency_ms / 1000).toFixed(2)}s`
              }
            />
            <StatRow label="Tokens in" value={formatCompact(totals.tokens_in)} />
            <StatRow label="Tokens out" value={formatCompact(totals.tokens_out)} />
            <StatRow label="Spend" value={formatCost(totals.cost)} emphasis />
          </dl>

          {/* The written finding D11 §10 asks for, and only when there is
              one to state. */}
          {totals.unanswered > 0 && (
            <p className="mt-4 rounded-lg border border-border bg-surface-sunken px-3 py-2 text-xs leading-relaxed text-content-secondary">
              {totals.unanswered.toLocaleString()} question
              {1 === totals.unanswered ? '' : 's'} found nothing confident in your
              knowledge. Each answer you write closes it for everybody who asks
              next.
            </p>
          )}
        </Card>
      </div>
    </div>
  );
}
