import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Pause, Play, Save } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Skeleton } from '@/components/ui/Skeleton';
import { StatusDot } from '@/components/ui/StatusDot';
import { toast } from '@/components/ui/Toast';
import { cn } from '@/lib/cn';
import { dutyStatus } from './status';
import { TestConsole } from './TestConsole';
import { JobTab } from './tabs/JobTab';
import { KnowledgeTab } from './tabs/KnowledgeTab';
import { GuardrailsTab } from './tabs/GuardrailsTab';
import { AppearanceTab } from './tabs/AppearanceTab';
import { DisplayTab } from './tabs/DisplayTab';
import { LeadsTab } from './tabs/LeadsTab';
import {
  useAgent,
  useAgentAction,
  useUpdateAgent,
  type AgentDetail,
  type AgentInput,
} from '@/api/queries/useAgents';

const TABS = [
  { key: 'job', label: 'Job description' },
  { key: 'knowledge', label: 'Knowledge' },
  { key: 'guardrails', label: 'Guardrails' },
  { key: 'appearance', label: 'Appearance' },
  { key: 'display', label: 'Where it appears' },
  { key: 'leads', label: 'Leads' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

/**
 * The clerk editor: six tabs on the left, a live test console on the right.
 *
 * The console is permanent rather than a modal, which is the whole design
 * of this screen: every edit is verifiable in the same breath it is made.
 * A test that lives behind a button gets used once, at the end, when the
 * operator has already stopped believing they will need it.
 *
 * The tabs are in-page state and not routes, deliberately and against the
 * usual rule in this codebase. They are six views of one unsaved form; a
 * URL per tab implies each is separately loadable, and loading one would
 * mean discarding whatever is unsaved in the other five.
 */
export function ClerkEditor() {
  const { uuid = '' } = useParams();
  const navigate = useNavigate();

  const { data, isPending, isError, error, refetch } = useAgent(uuid);
  const update = useUpdateAgent(uuid);
  const publish = useAgentAction(uuid, 'publish');
  const pause = useAgentAction(uuid, 'pause');

  const [tab, setTab] = useState<TabKey>('job');
  const [draft, setDraft] = useState<AgentInput>({});

  // The draft holds only what has been touched. Sending the whole clerk
  // back would make every save a write of every field, including the ones
  // another tab — or another person — changed a second ago.
  useEffect(() => {
    setDraft({});
  }, [uuid]);

  const dirty = Object.keys(draft).length > 0;

  const merged: AgentDetail | null = useMemo(() => {
    if (!data) {
      return null;
    }

    return { ...data, ...(draft as Partial<AgentDetail>) };
  }, [data, draft]);

  useEffect(() => {
    if (!dirty) {
      return;
    }

    const warn = (event: BeforeUnloadEvent) => event.preventDefault();

    window.addEventListener('beforeunload', warn);

    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  if (isPending || !merged) {
    return (
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">
        <Skeleton className="h-96 w-full rounded-xl" />
        <Skeleton className="h-96 w-full rounded-xl" />
      </div>
    );
  }

  const set = (patch: AgentInput) => setDraft((current) => ({ ...current, ...patch }));

  const save = () => {
    if (!dirty) {
      return;
    }

    update.mutate(draft, {
      onSuccess: () => {
        setDraft({});
        toast.success('Saved.');
      },
      onError: (failure) => toast.error('Not saved', failure.message),
    });
  };

  const onDuty = merged.status === 'published';

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          <Button size="sm" variant="ghost" onClick={() => void navigate('/clerks')}>
            <ArrowLeft size={14} aria-hidden="true" />
            Employees
          </Button>

          <span className="text-content-tertiary" aria-hidden="true">
            /
          </span>

          <h1 className="truncate font-display text-lg font-bold tracking-[-0.01em] text-content">
            {merged.name}
          </h1>

          <StatusDot status={dutyStatus(merged)} />

          {dirty && <Badge tone="warning">Unsaved</Badge>}
        </div>

        <div className="flex shrink-0 items-center gap-2">
          {onDuty ? (
            <Button
              loading={pause.isPending}
              onClick={() =>
                pause.mutate(undefined, {
                  onSuccess: () => toast.success(`${merged.name} is off duty.`),
                  onError: (failure) => toast.error('Could not pause', failure.message),
                })
              }
            >
              <Pause size={14} aria-hidden="true" />
              Pause
            </Button>
          ) : (
            <Button
              loading={publish.isPending}
              onClick={() =>
                publish.mutate(undefined, {
                  onSuccess: () => toast.success(`${merged.name} is on duty.`),
                  onError: (failure) => toast.error('Not published', failure.message),
                })
              }
            >
              <Play size={14} aria-hidden="true" />
              Publish
            </Button>
          )}

          <Button variant="primary" loading={update.isPending} disabled={!dirty} onClick={save}>
            <Save size={14} aria-hidden="true" />
            Save
          </Button>
        </div>
      </header>

      {merged.blockers.length > 0 && (
        <div className="rounded-xl border border-warning/25 bg-warning/10 px-4 py-3">
          <p className="text-sm font-medium text-content">
            Not ready to go on duty
          </p>
          <ul className="mt-1.5 space-y-1 text-sm text-content-secondary">
            {merged.blockers.map((blocker) => (
              <li key={blocker}>{blocker}</li>
            ))}
          </ul>
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">
        <div className="min-w-0 rounded-xl border border-border bg-surface">
          <div
            className="flex flex-wrap items-end gap-1 border-b border-border px-3 pt-2"
            role="tablist"
            aria-label="Clerk settings"
          >
            {TABS.map((item) => {
              const active = item.key === tab;

              return (
                <button
                  key={item.key}
                  type="button"
                  role="tab"
                  aria-selected={active}
                  onClick={() => setTab(item.key)}
                  className={cn(
                    'relative px-3 pb-2.5 pt-1 text-sm transition-colors',
                    'focus:outline-none focus-visible:text-content',
                    active
                      ? 'font-medium text-content'
                      : 'text-content-tertiary hover:text-content-secondary'
                  )}
                >
                  {item.label}
                  <span
                    aria-hidden="true"
                    className={cn(
                      'hvc-gradient-brand absolute inset-x-1 bottom-[-1px] h-0.5 rounded-full transition-opacity',
                      active ? 'opacity-100' : 'opacity-0'
                    )}
                  />
                </button>
              );
            })}
          </div>

          <div className="p-5">
            {tab === 'job' && <JobTab agent={merged} onChange={set} />}
            {tab === 'knowledge' && <KnowledgeTab agent={merged} onChange={set} />}
            {tab === 'guardrails' && <GuardrailsTab agent={merged} onChange={set} />}
            {tab === 'appearance' && <AppearanceTab agent={merged} onChange={set} />}
            {tab === 'display' && <DisplayTab agent={merged} onChange={set} />}
            {tab === 'leads' && <LeadsTab agent={merged} onChange={set} />}
          </div>
        </div>

        <TestConsole agent={merged} dirty={dirty} onSave={save} saving={update.isPending} />
      </div>
    </div>
  );
}
