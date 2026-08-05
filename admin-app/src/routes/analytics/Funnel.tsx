import { Link } from 'react-router';
import { ArrowRight } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Skeleton } from '@/components/ui/Skeleton';
import { BarRow } from '@/components/charts/BarRow';
import { useFunnel } from '@/api/queries/useAnalytics';
import { useReportFilters } from './AnalyticsShell';

/**
 * Where visitors stop (D11 §10, FR-ANL-05).
 *
 * The written finding under the bars is the point of the screen. A funnel
 * that only renders bars leaves the reader to work out which rung to fix,
 * which is the entire job the screen exists to do.
 */
export function Funnel() {
  const filters = useReportFilters();
  const { data, isPending, isError, error, refetch } = useFunnel(filters);

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  if (isPending) {
    return <Skeleton className="h-[320px] w-full rounded-xl" />;
  }

  const top = data.steps[0]?.count ?? 0;

  if (0 === top) {
    return (
      <Card>
        <EmptyState
          title="No conversations in this period."
          description="The funnel measures what happens after somebody starts talking. Widen the range, or wait for the first conversation."
        />
      </Card>
    );
  }

  return (
    <Card eyebrow="Lead funnel" title="Conversation to customer">
      <div className="space-y-1">
        {data.steps.map((step, index) => (
          <BarRow
            key={step.key}
            feature={0 === index}
            label={step.label}
            value={
              step.rate === null
                ? step.count.toLocaleString()
                : `${step.count.toLocaleString()}  ·  ${(step.rate * 100).toFixed(1)}%`
            }
            fraction={step.count / top}
            detail={
              step.rate === null
                ? undefined
                : `${step.drop_off.toLocaleString()} did not reach this step`
            }
          />
        ))}
      </div>

      {data.finding && (
        <div className="mt-5 rounded-lg border border-border bg-surface-sunken p-3">
          <p className="text-sm leading-relaxed text-content">
            <span className="font-medium">Biggest drop-off.</span>{' '}
            {data.finding.text}
          </p>

          {/* The finding links to the thing that fixes it. A finding
              without a next step is an observation. */}
          {('captured' === data.finding.step || 'engaged' === data.finding.step) && (
            <Link
              to="/clerks"
              className="mt-2 inline-flex items-center gap-1 text-sm font-medium text-accent-text hover:underline"
            >
              Review your clerks&rsquo; capture prompts
              <ArrowRight size={14} aria-hidden="true" />
            </Link>
          )}

          {'qualified' === data.finding.step && (
            <Link
              to="/leads/scoring"
              className="mt-2 inline-flex items-center gap-1 text-sm font-medium text-accent-text hover:underline"
            >
              Review your scoring rules
              <ArrowRight size={14} aria-hidden="true" />
            </Link>
          )}

          {'won' === data.finding.step && (
            <Link
              to="/leads/pipeline"
              className="mt-2 inline-flex items-center gap-1 text-sm font-medium text-accent-text hover:underline"
            >
              Open the pipeline
              <ArrowRight size={14} aria-hidden="true" />
            </Link>
          )}
        </div>
      )}
    </Card>
  );
}
