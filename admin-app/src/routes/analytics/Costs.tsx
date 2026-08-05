import { Card, StatRow } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Skeleton } from '@/components/ui/Skeleton';
import { TrendChart } from '@/components/charts/TrendChart';
import { BarRow } from '@/components/charts/BarRow';
import { useAgentReport, useOverview } from '@/api/queries/useAnalytics';
import { useCosts } from '@/api/queries/useCosts';
import { useReportFilters } from './AnalyticsShell';
import { formatCompact, formatCost } from '@/lib/format';

/**
 * Where the model spend went (FR-ANL-04, D11 §10).
 *
 * Three answers to three different questions: what it cost over time,
 * which clerk spent it, and which model. The per-model split comes from
 * the usage log rather than the rollup, because a model name is not a
 * dimension the daily table carries.
 */
export function Costs() {
  const filters = useReportFilters();
  const overview = useOverview(filters);
  const roster = useAgentReport(filters);
  const models = useCosts();

  if (overview.isError) {
    return (
      <ErrorNotice error={overview.error} onRetry={() => void overview.refetch()} />
    );
  }

  if (overview.isPending) {
    return <Skeleton className="h-[320px] w-full rounded-xl" />;
  }

  const totals = overview.data.totals;

  if (0 === totals.cost && 0 === totals.tokens_in) {
    return (
      <Card>
        <EmptyState
          title="Nothing has been billed in this period."
          description="Spend appears here as soon as a clerk answers a question or a source is indexed."
        />
      </Card>
    );
  }

  const spenders = roster.data?.agents.filter((row) => row.cost > 0) ?? [];
  const biggest = Math.max(...spenders.map((row) => row.cost), 0.000001);
  const unpriced = models.data?.total.unpriced ?? 0;

  return (
    <div className="space-y-5">
      <Card
        eyebrow="Spend"
        title={`${formatCost(totals.cost)} in this period`}
        actions={
          totals.cost_per_conversation !== null ? (
            <span className="font-mono text-xs tabular-nums text-content-tertiary">
              {formatCost(totals.cost_per_conversation)} per conversation
            </span>
          ) : undefined
        }
      >
        <TrendChart
          label="Spend"
          points={overview.data.series.map((day) => ({
            date: day.date,
            value: day.cost,
          }))}
          format={formatCost}
        />

        {unpriced > 0 && (
          // The honest caveat. A total that quietly omits calls it could
          // not price looks identical to one that priced everything.
          <p className="mt-4 rounded-lg border border-border bg-surface-sunken px-3 py-2 text-xs leading-relaxed text-content-secondary">
            {unpriced.toLocaleString()} call{1 === unpriced ? '' : 's'} used a model
            with no published price and contributed nothing to this figure. The
            real total is higher.
          </p>
        )}
      </Card>

      <div className="grid gap-5 lg:grid-cols-2">
        <Card eyebrow="By clerk" title="Who is spending it">
          {0 === spenders.length ? (
            <EmptyState
              bare
              title="No clerk has been billed in this period."
              description="Indexing spends money too, and it is not charged to a clerk."
            />
          ) : (
            <div className="space-y-1">
              {spenders.map((row) => (
                <BarRow
                  key={row.agent.uuid}
                  label={row.agent.name}
                  value={formatCost(row.cost)}
                  fraction={row.cost / biggest}
                />
              ))}
            </div>
          )}
        </Card>

        <Card eyebrow="By model" title="What the providers charged">
          {models.isPending ? (
            <Skeleton className="h-[160px] w-full" />
          ) : (models.data?.by_model.length ?? 0) === 0 ? (
            <EmptyState bare title="No provider calls recorded yet." />
          ) : (
            <dl>
              {models.data?.by_model.map((slice) => (
                <StatRow
                  key={`${slice.provider}:${slice.label}`}
                  label={`${slice.provider} · ${slice.label}`}
                  value={
                    slice.complete
                      ? formatCost(slice.cost)
                      : // Not a cost, because we do not have one.
                        `${formatCompact(slice.tokens_in + slice.tokens_out)} tokens · unpriced`
                  }
                />
              ))}
            </dl>
          )}

          <p className="mt-4 text-xs leading-relaxed text-content-tertiary">
            The by-model split covers the last 30 days regardless of the range
            above — it is read from the usage log, which is not summarised per
            day.
          </p>
        </Card>
      </div>
    </div>
  );
}
