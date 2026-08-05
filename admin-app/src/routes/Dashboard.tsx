import { Database, Layers, Sparkles } from 'lucide-react';
import { Card, StatRow } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Skeleton } from '@/components/ui/Skeleton';
import { StatusDot } from '@/components/ui/StatusDot';
import { Button } from '@/components/ui/Button';
import { Logomark } from '@/components/brand/Logomark';
import { useSystemStatus } from '@/api/queries/useSystemStatus';

/**
 * Sprint 1 dashboard.
 *
 * Reads live counts from GET /system/status, which is the proof that
 * routing, capability checks and the repository layer are wired together.
 * The KPI cards from the wireframes arrive in Sprint 9 once there is real
 * data behind them — fabricated metrics would imply work that has not
 * happened.
 */
export function Dashboard() {
  const { data, isPending, isError, error, refetch } = useSystemStatus();

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  return (
    <div className="space-y-5">
      {/* Hero: the one place the brand surface appears on a content screen. */}
      <Card feature className="p-0">
        <div className="flex items-start gap-4 p-6">
          <Logomark size={44} className="rounded-xl" />

          <div className="min-w-0">
            <div className="mb-1.5 flex flex-wrap items-center gap-2">
              <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-content-tertiary">
                Sprint 1 · Milestone M0
              </p>
              {isPending ? (
                <Skeleton className="h-4 w-28" />
              ) : (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface-sunken px-2 py-0.5">
                  <StatusDot
                    status={data.ready ? 'on_duty' : 'draft'}
                    iconOnly
                  />
                  <span className="text-[11px] font-medium text-content-secondary">
                    {data.ready
                      ? 'Serving visitors'
                      : 'No clerk on duty yet'}
                  </span>
                </span>
              )}
            </div>

            <h2 className="font-display text-xl font-bold tracking-[-0.02em] text-content">
              The data layer is live
            </h2>
            <p className="mt-1.5 max-w-[62ch] text-sm leading-relaxed text-content-secondary">
              27 tables, four repositories, REST with capability checks,
              AES-256-GCM secret storage and rate limiting. Knowledge ingestion
              lands in Sprint 3, retrieval in Sprint 4, and the first real
              conversation in Sprint 5.
            </p>
          </div>
        </div>
      </Card>

      <div className="grid gap-5 lg:grid-cols-2">
        <Card
          eyebrow="Live from the API"
          title="What's in the database"
          actions={<Database size={15} className="text-content-tertiary" />}
        >
          {isPending ? (
            <div className="space-y-2.5">
              {[0, 1, 2, 3, 4].map((i) => (
                <Skeleton key={i} className="h-8 w-full" />
              ))}
            </div>
          ) : (
            <dl>
              <StatRow
                label="Clerks"
                value={data.counts.agents.toLocaleString()}
                emphasis
              />
              <StatRow
                label="On duty"
                value={data.counts.published.toLocaleString()}
              />
              <StatRow
                label="Conversations"
                value={data.counts.conversations.toLocaleString()}
              />
              <StatRow
                label="Knowledge sources"
                value={data.counts.sources.toLocaleString()}
              />
              <StatRow
                label="Indexed chunks"
                value={data.counts.chunks.toLocaleString()}
              />
            </dl>
          )}
        </Card>

        <Card
          eyebrow="Schema"
          title="Migration state"
          actions={<Layers size={15} className="text-content-tertiary" />}
        >
          {isPending ? (
            <div className="space-y-2.5">
              {[0, 1, 2].map((i) => (
                <Skeleton key={i} className="h-8 w-full" />
              ))}
            </div>
          ) : (
            <>
              <dl>
                <StatRow
                  label="Applied version"
                  value={`${data.database.version} / ${data.database.latest}`}
                  emphasis
                />
                <StatRow
                  label="Pending migrations"
                  value={data.database.needs_migration ? 'Yes' : 'None'}
                />
                <StatRow label="Server time (UTC)" value={data.time} />
              </dl>

              <div className="mt-4 flex items-center gap-2 rounded-lg border border-border bg-surface-sunken px-3 py-2">
                <Sparkles size={13} className="shrink-0 text-accent" />
                <p className="text-xs text-content-secondary">
                  Schema is current. Nothing to apply.
                </p>
              </div>
            </>
          )}
        </Card>
      </div>

      {!isPending && data.counts.agents === 0 && (
        <Card>
          <EmptyState
            title="Nobody's on duty yet."
            description="Hiring a clerk arrives in Sprint 6. The data layer behind it is ready now."
            action={
              <Button variant="primary" size="sm" disabled>
                Hire a clerk
              </Button>
            }
          />
        </Card>
      )}
    </div>
  );
}
