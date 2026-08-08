import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { ArrowLeft } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Card } from '@/components/ui/Card';
import { Drawer } from '@/components/ui/Drawer';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Skeleton } from '@/components/ui/Skeleton';
import {
  useWorkflow,
  useWorkflowRun,
  useWorkflowRuns,
  type RunStatus,
  type RunSummary,
} from '@/api/queries/useWorkflows';

const TONES: Record<RunStatus, 'positive' | 'neutral' | 'warning' | 'danger'> = {
  completed: 'positive',
  waiting: 'warning',
  pending: 'neutral',
  failed: 'danger',
  cancelled: 'neutral',
};

/**
 * What the workflow actually did (FR-WFL-07).
 *
 * ## The log is the answer to the only hard support question
 *
 * "Why did this lead get that email" is unanswerable without a per-step
 * record, and the record has to include the values a condition compared —
 * "Score was 45 → No", never "Score check → No". Everything here exists
 * to make that one sentence available to somebody a week later.
 *
 * ## Ninety days, said out loud
 *
 * Runs are pruned after ninety days, and the screen says so rather than
 * letting an operator conclude the older ones were never recorded.
 */
export function WorkflowRuns() {
  const { uuid = '' } = useParams();

  const workflow = useWorkflow(uuid);
  const runs = useWorkflowRuns(uuid, {});

  const [open, setOpen] = useState<number | null>(null);

  if (runs.isError) {
    return <ErrorNotice error={runs.error} onRetry={() => void runs.refetch()} />;
  }

  return (
    <div className="space-y-5">
      <Link
        to={`/workflows/${uuid}`}
        className="inline-flex items-center gap-1.5 text-sm text-content-secondary hover:text-content"
      >
        <ArrowLeft size={14} aria-hidden="true" />
        Back to the workflow
      </Link>

      <Card title={workflow.data ? `${workflow.data.name} · activity` : 'Activity'}>
        {runs.isLoading || !runs.data ? (
          <Skeleton className="h-48 w-full" />
        ) : runs.data.rows.length === 0 ? (
          <EmptyState
            bare
            title="Nobody has been through this yet"
            description="Runs appear here the moment the trigger fires. Each one records every step it took and why, so you can answer questions about it later."
          />
        ) : (
          <ul className="divide-y divide-border">
            {runs.data.rows.map((run) => (
              <RunRow key={run.id} run={run} onOpen={() => setOpen(run.id)} />
            ))}
          </ul>
        )}
      </Card>

      <p className="text-xs text-content-tertiary">
        Runs and their steps are kept for 90 days, then cleared.
      </p>

      <RunDrawer id={open} onClose={() => setOpen(null)} />
    </div>
  );
}

function RunRow({ run, onOpen }: { run: RunSummary; onOpen: () => void }) {
  return (
    <li>
      <button
        type="button"
        onClick={onOpen}
        className="flex w-full items-center justify-between gap-4 py-3 text-left transition-colors hover:bg-surface-hover"
      >
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <span className="truncate text-sm text-content">
              {run.subject_name ?? 'Anonymous visitor'}
            </span>
            <Badge tone={TONES[run.status]}>{run.status_label}</Badge>
          </div>

          {run.error !== null && (
            <p className="mt-0.5 text-xs text-content-tertiary">{run.error}</p>
          )}
        </div>

        <span className="shrink-0 text-xs text-content-tertiary">
          {run.steps} {run.steps === 1 ? 'step' : 'steps'}
        </span>
      </button>
    </li>
  );
}

function RunDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const run = useWorkflowRun(id);

  return (
    <Drawer
      open={id !== null}
      onClose={onClose}
      title="What happened"
      {...(run.data?.run.subject_name
        ? { subtitle: run.data.run.subject_name }
        : {})}
      width="lg"
    >
      <div className="p-5">
        {run.isLoading && <Skeleton className="h-40 w-full" />}

        {run.data && run.data.log.length === 0 && (
          <p className="text-sm text-content-secondary">
            This run has not taken a step yet. It is waiting for the next tick,
            which happens every five minutes.
          </p>
        )}

        {run.data && run.data.log.length > 0 && (
          <ol className="space-y-3">
            {run.data.log.map((line, index) => (
              <li key={index} className="flex gap-3">
                <span className="mt-1 text-[11px] font-mono tabular-nums text-content-tertiary">
                  {line.created_at === null
                    ? ''
                    : new Date(line.created_at).toLocaleTimeString()}
                </span>

                <span className="min-w-0">
                  <span className="block text-sm text-content">
                    {line.detail ?? line.label}
                  </span>
                  <span className="mt-0.5 block text-[11px] uppercase tracking-wide text-content-tertiary">
                    {line.node_type} · {line.label}
                  </span>
                </span>
              </li>
            ))}
          </ol>
        )}
      </div>
    </Drawer>
  );
}
