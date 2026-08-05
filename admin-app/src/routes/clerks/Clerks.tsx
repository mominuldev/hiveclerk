import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Copy, MoreHorizontal, Pause, Play, Plus, Trash2 } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Filters } from '@/components/ui/Filters';
import { Modal } from '@/components/ui/Modal';
import { Skeleton } from '@/components/ui/Skeleton';
import { StatusDot } from '@/components/ui/StatusDot';
import { toast } from '@/components/ui/Toast';
import { formatCompact, formatCost } from '@/lib/format';
import { cn } from '@/lib/cn';
import { HireModal } from './HireModal';
import { dutyStatus } from './status';
import {
  useAgentAction,
  useAgents,
  useDeleteAgent,
  type AgentSummary,
} from '@/api/queries/useAgents';

/**
 * The roster.
 *
 * Each card reads like a personnel record — role, start date, workload,
 * results, cost — rather than a settings row, because the question an
 * operator brings to this screen is "is this clerk doing its job", and
 * that is not a question a name and a toggle can answer.
 */
export function Clerks() {
  const [status, setStatus] = useState('');
  const [role, setRole] = useState('');
  const [search, setSearch] = useState('');
  const [hiring, setHiring] = useState(false);

  const filters = {
    ...(status ? { status } : {}),
    ...(role ? { role } : {}),
    ...(search ? { search } : {}),
  };

  const { data, isPending, isError, error, refetch } = useAgents(filters);
  const anyFilter = status !== '' || role !== '' || search !== '';

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Filters
          search={{ value: search, onChange: setSearch, placeholder: 'Search clerks' }}
          selects={[
            {
              key: 'status',
              label: 'Status',
              value: status,
              onChange: setStatus,
              options: [
                { value: 'published', label: 'On duty' },
                { value: 'paused', label: 'Paused' },
                { value: 'draft', label: 'Draft' },
              ],
            },
            {
              key: 'role',
              label: 'Role',
              value: role,
              onChange: setRole,
              options: [
                { value: 'support', label: 'Support' },
                { value: 'sales', label: 'Sales' },
                { value: 'qualifier', label: 'Lead qualifier' },
                { value: 'faq', label: 'FAQ' },
                { value: 'concierge', label: 'Concierge' },
                { value: 'custom', label: 'Custom' },
              ],
            },
          ]}
          onClear={() => {
            setStatus('');
            setRole('');
            setSearch('');
          }}
        />

        <Button variant="primary" onClick={() => setHiring(true)}>
          <Plus size={15} aria-hidden="true" />
          Hire a clerk
        </Button>
      </div>

      {isPending ? (
        <div className="space-y-3">
          {[0, 1].map((i) => (
            <Skeleton key={i} className="h-28 w-full rounded-xl" />
          ))}
        </div>
      ) : data.agents.length === 0 ? (
        <EmptyState
          title={anyFilter ? 'No clerks match that' : 'Nobody is on the payroll yet'}
          description={
            anyFilter
              ? 'Clear the filters to see everyone.'
              : 'Hire your first clerk, point it at your content, and it will start answering. The role presets come with their instructions already written.'
          }
          action={
            anyFilter ? undefined : (
              <Button variant="primary" onClick={() => setHiring(true)}>
                <Plus size={15} aria-hidden="true" />
                Hire your first clerk
              </Button>
            )
          }
        />
      ) : (
        <div className="space-y-3">
          {data.agents.map((agent) => (
            <ClerkCard key={agent.uuid} agent={agent} />
          ))}
        </div>
      )}

      <HireModal open={hiring} onClose={() => setHiring(false)} />
    </div>
  );
}

function ClerkCard({ agent }: { agent: AgentSummary }) {
  const navigate = useNavigate();
  const [confirming, setConfirming] = useState(false);

  const publish = useAgentAction(agent.uuid, 'publish');
  const pause = useAgentAction(agent.uuid, 'pause');
  const duplicate = useAgentAction(agent.uuid, 'duplicate');
  const remove = useDeleteAgent();

  const onDuty = agent.status === 'published';
  const stats = agent.stats;

  return (
    <Card className="!p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex items-center gap-2.5">
            <StatusDot status={dutyStatus(agent)} iconOnly />
            <button
              type="button"
              onClick={() => void navigate(`/clerks/${agent.uuid}`)}
              className="font-display text-base font-bold tracking-[-0.01em] text-content hover:underline"
            >
              {agent.name}
            </button>
            {agent.budget.warning && (
              <Badge tone="warning">
                {Math.round(agent.budget.ratio * 100)}% of budget
              </Badge>
            )}
            {agent.budget.blocking && <Badge tone="danger">Budget spent</Badge>}
          </div>

          <p className="mt-1 text-sm text-content-secondary">
            {agent.role_label}
            {' · '}
            {onDuty ? 'On duty' : agent.status_label}
            {agent.created_at ? ` since ${agent.created_at.slice(0, 10)}` : ''}
          </p>
        </div>

        <div className="flex shrink-0 items-center gap-1.5">
          {onDuty ? (
            <Button
              size="sm"
              loading={pause.isPending}
              onClick={() => {
                pause.mutate(undefined, {
                  onSuccess: () => toast.success(`${agent.name} is off duty.`),
                  onError: (error) => toast.error('Could not pause', error.message),
                });
              }}
            >
              <Pause size={13} aria-hidden="true" />
              Pause
            </Button>
          ) : (
            <Button
              size="sm"
              loading={publish.isPending}
              onClick={() => {
                publish.mutate(undefined, {
                  onSuccess: () => toast.success(`${agent.name} is on duty.`),
                  onError: (error) =>
                    // Both the licence cap and a missing model land here,
                    // and the server's own wording says which.
                    toast.error('Not published', error.message),
                });
              }}
            >
              <Play size={13} aria-hidden="true" />
              Publish
            </Button>
          )}

          <Button
            size="sm"
            variant="ghost"
            aria-label={`Duplicate ${agent.name}`}
            loading={duplicate.isPending}
            onClick={() => {
              duplicate.mutate(undefined, {
                onSuccess: () => toast.success(`Copied ${agent.name}.`),
                onError: (error) => toast.error('Could not copy', error.message),
              });
            }}
          >
            <Copy size={13} aria-hidden="true" />
          </Button>

          <Button
            size="sm"
            variant="ghost"
            aria-label={`Retire ${agent.name}`}
            onClick={() => setConfirming(true)}
          >
            <Trash2 size={13} aria-hidden="true" />
          </Button>

          <Button
            size="sm"
            variant="secondary"
            onClick={() => void navigate(`/clerks/${agent.uuid}`)}
          >
            <MoreHorizontal size={13} aria-hidden="true" />
            Open
          </Button>
        </div>
      </div>

      <dl className="mt-3 flex flex-wrap items-baseline gap-x-6 gap-y-1 text-xs text-content-tertiary">
        {/* Nulls, not zeroes. "0 conversations · 0% resolved" on a clerk
            hired an hour ago reads as a clerk that is failing. */}
        <Stat
          label={stats ? `conversations · ${stats.days}d` : 'conversations'}
          value={stats ? stats.conversations.toLocaleString() : 'None yet'}
        />
        <Stat
          label="resolved without a person"
          value={
            stats && stats.conversations > 0
              ? `${Math.round((stats.resolved / stats.conversations) * 100)}%`
              : '—'
          }
        />
        <Stat
          label="sources"
          value={agent.source_count === 0 ? 'None' : String(agent.source_count)}
        />
        <Stat label="spend" value={stats ? formatCost(stats.cost) : '—'} />
      </dl>

      {agent.budget.tokens !== null && (
        <div className="mt-3">
          <div
            className="h-1.5 w-full overflow-hidden rounded-full bg-surface-sunken"
            role="img"
            aria-label={`Token budget ${Math.round(agent.budget.ratio * 100)}% used`}
          >
            <div
              className={cn(
                'h-full rounded-full transition-all',
                agent.budget.blocking
                  ? 'bg-danger'
                  : agent.budget.warning
                    ? 'bg-warning'
                    : 'bg-accent'
              )}
              style={{ width: `${Math.min(100, agent.budget.ratio * 100)}%` }}
            />
          </div>
          <p className="mt-1.5 font-mono text-[11px] tabular-nums text-content-tertiary">
            {formatCompact(agent.budget.used)} of{' '}
            {formatCompact(agent.budget.tokens)} tokens · resets{' '}
            {agent.budget.resets_at.slice(0, 10)}
          </p>
        </div>
      )}

      <Modal
        open={confirming}
        onClose={() => setConfirming(false)}
        title={`Retire ${agent.name}?`}
        description="The clerk stops answering immediately. Its conversations stay in the record, attributed to it, so past answers remain auditable."
        danger
        footer={
          <>
            <Button onClick={() => setConfirming(false)}>Keep it</Button>
            <Button
              variant="danger"
              loading={remove.isPending}
              onClick={() => {
                remove.mutate(agent.uuid, {
                  onSuccess: () => {
                    setConfirming(false);
                    toast.success(`${agent.name} retired.`);
                  },
                  onError: (error) => toast.error('Could not retire', error.message),
                });
              }}
            >
              Retire
            </Button>
          </>
        }
      />
    </Card>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline gap-1.5">
      <dd className="font-mono text-[13px] tabular-nums text-content">{value}</dd>
      <dt>{label}</dt>
    </div>
  );
}
