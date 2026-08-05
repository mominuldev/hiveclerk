import { Link } from 'react-router-dom';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Skeleton } from '@/components/ui/Skeleton';
import { StatusDot } from '@/components/ui/StatusDot';
import { BarRow } from '@/components/charts/BarRow';
import { useAgentReport } from '@/api/queries/useAnalytics';
import { useReportFilters } from './AnalyticsShell';
import { formatCost } from '@/lib/format';

/**
 * Per-clerk comparison (FR-ANL-02).
 *
 * Ordered by conversations rather than by deflection rate. A clerk that
 * answered four questions perfectly is not outperforming one that
 * answered six hundred at 79%, and sorting by rate would put it top.
 */
export function ClerkReport() {
  const filters = useReportFilters();
  const { data, isPending, isError, error, refetch } = useAgentReport(filters);

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  if (isPending) {
    return <Skeleton className="h-[320px] w-full rounded-xl" />;
  }

  const rows = data.agents;
  const busiest = Math.max(...rows.map((row) => row.conversations), 1);
  const worked = rows.filter((row) => row.conversations > 0);

  if (0 === worked.length) {
    return (
      <Card>
        <EmptyState
          title="No clerk was asked anything in this period."
          description="Comparing them needs conversations to compare. Widen the range, or check that a clerk is on duty."
        />
      </Card>
    );
  }

  return (
    <div className="space-y-5">
      <Card eyebrow="Workload" title="Who handled what">
        <div className="space-y-1">
          {rows.map((row) => (
            <BarRow
              key={row.agent.uuid}
              label={
                <span className="inline-flex items-center gap-2">
                  <StatusDot status={row.agent.status as never} iconOnly />
                  <Link
                    to={`/clerks/${row.agent.uuid}`}
                    className="hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                  >
                    {row.agent.name}
                  </Link>
                </span>
              }
              value={`${row.conversations.toLocaleString()} conv`}
              fraction={row.conversations / busiest}
              detail={
                row.deflection_rate === null
                  ? 'No conversations in this period'
                  : `${Math.round(row.deflection_rate * 100)}% answered without a person · ${row.leads_qualified.toLocaleString()} qualified · ${formatCost(row.cost)}`
              }
            />
          ))}
        </div>
      </Card>

      <Card eyebrow="Detail" title="Every figure, per clerk">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border text-left text-xs text-content-tertiary">
                <th scope="col" className="py-2 pr-4 font-medium">Clerk</th>
                <th scope="col" className="py-2 pr-4 text-right font-medium">Conversations</th>
                <th scope="col" className="py-2 pr-4 text-right font-medium">Captured</th>
                <th scope="col" className="py-2 pr-4 text-right font-medium">Qualified</th>
                <th scope="col" className="py-2 pr-4 text-right font-medium">Handoffs</th>
                <th scope="col" className="py-2 pr-4 text-right font-medium">Deflection</th>
                <th scope="col" className="py-2 pr-4 text-right font-medium">Latency</th>
                <th scope="col" className="py-2 text-right font-medium">Spend</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.agent.uuid} className="border-b border-border last:border-0">
                  <th scope="row" className="py-2 pr-4 text-left font-normal text-content">
                    {row.agent.name}
                  </th>
                  <td className="py-2 pr-4 text-right font-mono tabular-nums text-content-secondary">
                    {row.conversations.toLocaleString()}
                  </td>
                  <td className="py-2 pr-4 text-right font-mono tabular-nums text-content-secondary">
                    {row.leads_captured.toLocaleString()}
                  </td>
                  <td className="py-2 pr-4 text-right font-mono tabular-nums text-content">
                    {row.leads_qualified.toLocaleString()}
                  </td>
                  <td className="py-2 pr-4 text-right font-mono tabular-nums text-content-secondary">
                    {row.handoffs.toLocaleString()}
                  </td>
                  <td className="py-2 pr-4 text-right font-mono tabular-nums text-content-secondary">
                    {/* An em dash, not 0%. Nobody spoke to this clerk. */}
                    {row.deflection_rate === null
                      ? '—'
                      : `${Math.round(row.deflection_rate * 100)}%`}
                  </td>
                  <td className="py-2 pr-4 text-right font-mono tabular-nums text-content-secondary">
                    {row.avg_latency_ms === null
                      ? '—'
                      : `${(row.avg_latency_ms / 1000).toFixed(2)}s`}
                  </td>
                  <td className="py-2 text-right font-mono tabular-nums text-content-secondary">
                    {formatCost(row.cost)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  );
}
