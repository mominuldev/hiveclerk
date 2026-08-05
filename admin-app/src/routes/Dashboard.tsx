import { useState } from 'react';
import { Link } from 'react-router';
import { AlertTriangle, ArrowRight, CircleAlert, Info } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Skeleton } from '@/components/ui/Skeleton';
import { Button } from '@/components/ui/Button';
import { KpiCard } from '@/components/analytics/KpiCard';
import {
  RangePicker,
  rangeParams,
  type RangeDays,
} from '@/components/analytics/RangePicker';
import { TrendChart } from '@/components/charts/TrendChart';
import { BarRow } from '@/components/charts/BarRow';
import { useOverview, useAgentReport, type Alert } from '@/api/queries/useAnalytics';
import { cn } from '@/lib/cn';

const SEVERITY_ICON = {
  urgent: CircleAlert,
  warning: AlertTriangle,
  info: Info,
} as const;

const SEVERITY_COLOUR = {
  urgent: 'text-[var(--hvc-danger)]',
  warning: 'text-[var(--hvc-warning)]',
  info: 'text-content-tertiary',
} as const;

/**
 * The first screen of the product (D11 §3).
 *
 * Qualified leads leads the KPI row because it is the PRD's North Star:
 * conversation count can rise while the product fails, and qualified
 * conversations cannot.
 *
 * Everything here comes from one request. The dashboard renders all of it
 * at once, and five queries would be five chances for the screen to
 * assemble itself in front of the reader.
 */
export function Dashboard() {
  const [days, setDays] = useState<RangeDays>(30);
  const [agent, setAgent] = useState('all');

  const filters = { ...rangeParams(days), agent };
  const overview = useOverview(filters);
  const roster = useAgentReport(filters);

  if (overview.isError) {
    return (
      <ErrorNotice
        error={overview.error}
        onRetry={() => void overview.refetch()}
      />
    );
  }

  const data = overview.data;
  const alerts = data?.alerts ?? [];
  const banner = alerts.find((alert) => 'knowledge_gaps' === alert.kind);
  const queue = alerts.filter((alert) => 'knowledge_gaps' !== alert.kind);

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="font-display text-xl font-bold tracking-[-0.02em] text-content">
          Dashboard
        </h1>
        <RangePicker
          days={days}
          onDays={setDays}
          agent={agent}
          onAgent={setAgent}
        />
      </header>

      {banner && (
        <Link
          to={banner.href}
          className={cn(
            'flex items-center justify-between gap-4 rounded-xl border border-border bg-surface px-4 py-3',
            'transition-colors hover:border-border-strong hover:bg-surface-hover',
            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent'
          )}
        >
          <span className="flex min-w-0 items-center gap-2.5">
            <AlertTriangle
              size={16}
              aria-hidden="true"
              className="shrink-0 text-[var(--hvc-warning)]"
            />
            <span className="min-w-0 text-sm text-content">{banner.title}</span>
          </span>
          <span className="flex shrink-0 items-center gap-1 text-sm font-medium text-accent-text">
            Review knowledge gaps
            <ArrowRight size={14} aria-hidden="true" />
          </span>
        </Link>
      )}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {overview.isPending
          ? [0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-[148px] rounded-xl" />)
          : data?.kpis.map((kpi, index) => (
              <KpiCard key={kpi.key} kpi={kpi} feature={index === 0} />
            ))}
      </div>

      <div className="grid gap-5 lg:grid-cols-[1.6fr_1fr]">
        <Card eyebrow="Conversation volume" title="How busy your clerks have been">
          {overview.isPending ? (
            <Skeleton className="h-[180px] w-full" />
          ) : (
            <TrendChart
              label="Conversations"
              points={(data?.series ?? []).map((day) => ({
                date: day.date,
                value: day.conversations,
              }))}
            />
          )}
        </Card>

        <Card eyebrow="Roster performance" title="Who is carrying the load">
          {roster.isPending ? (
            <Skeleton className="h-[180px] w-full" />
          ) : (data?.totals.conversations ?? 0) === 0 ? (
            <EmptyState
              bare
              title="Nobody has been asked anything yet."
              description="Once a clerk is on duty and visitors start talking, this compares them."
            />
          ) : (
            <div>
              {(roster.data?.agents ?? []).slice(0, 6).map((row) => {
                const busiest = Math.max(
                  ...(roster.data?.agents ?? []).map((a) => a.conversations),
                  1
                );

                return (
                  <BarRow
                    key={row.agent.uuid}
                    label={row.agent.name}
                    value={`${row.conversations.toLocaleString()} conv`}
                    fraction={row.conversations / busiest}
                    detail={
                      row.deflection_rate === null
                        ? // Not "0% deflection". That is a judgement about
                          // a clerk nobody spoke to.
                          'No conversations in this period'
                        : `${Math.round(row.deflection_rate * 100)}% answered without a person`
                    }
                  />
                );
              })}
            </div>
          )}
        </Card>
      </div>

      <div className="grid gap-5 lg:grid-cols-2">
        <Card
          eyebrow="Top questions"
          title="What visitors arrive asking"
          actions={
            <Link to="/analytics/topics">
              <Button variant="ghost" size="sm">
                See all
              </Button>
            </Link>
          }
        >
          {overview.isPending ? (
            <Skeleton className="h-[160px] w-full" />
          ) : (data?.top_topics.length ?? 0) === 0 ? (
            <EmptyState
              bare
              title="No questions yet."
              description="This fills in as soon as somebody talks to a clerk."
            />
          ) : (
            <ul className="divide-y divide-border">
              {data?.top_topics.map((topic) => (
                <li
                  key={topic.label}
                  className="flex items-baseline justify-between gap-4 py-2 text-sm"
                >
                  <span className="min-w-0 truncate text-content">{topic.label}</span>
                  <span className="shrink-0 font-mono text-[13px] tabular-nums text-content-secondary">
                    {topic.count}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card eyebrow="Needs attention" title="What is waiting on a person">
          {overview.isPending ? (
            <Skeleton className="h-[160px] w-full" />
          ) : queue.length === 0 ? (
            <EmptyState bare title="Nothing needs you right now." />
          ) : (
            <ul className="divide-y divide-border">
              {queue.map((alert) => (
                <AlertRow key={`${alert.kind}-${alert.title}`} alert={alert} />
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  );
}

function AlertRow({ alert }: { alert: Alert }) {
  const Icon = SEVERITY_ICON[alert.severity];

  return (
    <li>
      <Link
        to={alert.href}
        className={cn(
          'flex items-start gap-2.5 py-2.5',
          'transition-colors hover:text-content',
          'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent'
        )}
      >
        <Icon
          size={14}
          aria-hidden="true"
          className={cn('mt-0.5 shrink-0', SEVERITY_COLOUR[alert.severity])}
        />
        <span className="min-w-0">
          <span className="block text-sm text-content">{alert.title}</span>
          {alert.detail && (
            <span className="mt-0.5 block truncate text-xs text-content-tertiary">
              {alert.detail}
            </span>
          )}
        </span>
      </Link>
    </li>
  );
}
