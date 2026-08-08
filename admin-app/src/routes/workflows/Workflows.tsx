import { useState } from 'react';
import { Link } from 'react-router';
import { Plus, Sparkles, Workflow as WorkflowIcon } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Field, Input } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Skeleton } from '@/components/ui/Skeleton';
import { toast } from '@/components/ui/Toast';
import {
  useCreateWorkflow,
  useWorkflows,
  type WorkflowStatus,
  type WorkflowSummary,
  type WorkflowTemplate,
} from '@/api/queries/useWorkflows';

const TONES: Record<WorkflowStatus, 'positive' | 'neutral' | 'warning'> = {
  active: 'positive',
  draft: 'neutral',
  paused: 'warning',
  archived: 'neutral',
};

/**
 * The workflow list (FR-WFL-05).
 *
 * ## Templates are the primary path, not a secondary one
 *
 * An empty automation canvas is the hardest screen in any product of this
 * kind: everything is possible and nothing is suggested. So the empty
 * state offers four workflows worth having rather than a blank one, and
 * each arrives as a draft with the site-specific decisions still to make
 * — which means opening one is a tour of the builder rather than a thing
 * that silently starts emailing people.
 *
 * ## Counts, and only where there are counts
 *
 * A workflow nobody has been through says so in words. "0 waiting · 0
 * finished · 0 failed" reads like a broken feature; "nobody has been
 * through this yet" reads like a new one.
 */
export function Workflows() {
  const list = useWorkflows();
  const create = useCreateWorkflow();

  const [naming, setNaming] = useState(false);
  const [name, setName] = useState('');

  if (list.isError) {
    return <ErrorNotice error={list.error} onRetry={() => void list.refetch()} />;
  }

  if (list.isLoading || !list.data) {
    return <Skeleton className="h-64 w-full" />;
  }

  const { workflows, templates } = list.data;

  const start = (input: { name?: string; template?: string }): void => {
    create.mutate(input, {
      onSuccess: () => {
        toast.success(
          'Workflow created',
          'It is a draft until you activate it — nothing runs yet.'
        );
        setNaming(false);
        setName('');
      },
      onError: (error) => toast.error('That did not save', error.message),
    });
  };

  return (
    <div className="space-y-5">
      <Card
        title="Workflows"
        actions={
          <Button
            variant="primary"
            icon={<Plus size={15} aria-hidden="true" />}
            onClick={() => setNaming(true)}
          >
            New workflow
          </Button>
        }
      >
        {workflows.length === 0 ? (
          <EmptyState
            bare
            title="Nothing automated yet"
            description="A workflow watches for something happening — a lead qualifying, a visitor asking for a person — and then does what you would have done by hand. Start from one of these and change what you need."
          />
        ) : (
          <ul className="divide-y divide-border">
            {workflows.map((workflow) => (
              <WorkflowRow key={workflow.uuid} workflow={workflow} />
            ))}
          </ul>
        )}
      </Card>

      <Card title={workflows.length === 0 ? 'Start from one of these' : 'Templates'}>
        <p className="mb-3 text-xs leading-relaxed text-content-tertiary">
          Each one arrives as a draft, with the decisions that depend on your
          site left for you to make.
        </p>

        <ul className="grid gap-3 sm:grid-cols-2">
          {templates.map((template) => (
            <TemplateCard
              key={template.id}
              template={template}
              pending={create.isPending}
              onUse={() => start({ template: template.id })}
            />
          ))}
        </ul>
      </Card>

      <Modal
        open={naming}
        onClose={() => setNaming(false)}
        title="New workflow"
        description="Name it for what it does. You will choose what starts it next."
        footer={
          <>
            <Button onClick={() => setNaming(false)}>Cancel</Button>
            <Button
              variant="primary"
              loading={create.isPending}
              disabled={name.trim() === ''}
              onClick={() => start({ name })}
            >
              Create
            </Button>
          </>
        }
      >
        <Field label="Name">
          {({ id }) => (
            <Input
              id={id}
              autoFocus
              value={name}
              placeholder="Chase a qualified lead"
              onChange={(event) => setName(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter' && name.trim() !== '') {
                  start({ name });
                }
              }}
            />
          )}
        </Field>
      </Modal>
    </div>
  );
}

function WorkflowRow({ workflow }: { workflow: WorkflowSummary }) {
  const { waiting, completed, failed } = workflow.runs;
  const touched = waiting + completed + failed;

  return (
    <li>
      <Link
        to={`/workflows/${workflow.uuid}`}
        className="flex items-center justify-between gap-4 py-3 transition-colors hover:bg-surface-hover"
      >
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <span className="truncate text-sm font-medium text-content">
              {workflow.name}
            </span>
            <Badge tone={TONES[workflow.status]}>{workflow.status_label}</Badge>
            {failed > 0 && (
              <Badge tone="danger">
                {failed} failed
              </Badge>
            )}
          </div>

          <p className="mt-0.5 text-xs text-content-tertiary">
            {workflow.trigger_label} ·{' '}
            {workflow.steps === 0
              ? 'no steps yet'
              : `${workflow.steps} ${workflow.steps === 1 ? 'step' : 'steps'}`}
          </p>
        </div>

        <div className="shrink-0 text-right">
          {touched === 0 ? (
            <span className="text-xs text-content-tertiary">
              Nobody has been through this yet
            </span>
          ) : (
            <>
              <span className="font-mono text-[13px] tabular-nums text-content">
                {completed}
              </span>
              <span className="ml-1 text-xs text-content-tertiary">finished</span>
              <p className="text-[11px] text-content-tertiary">
                {waiting} in progress
              </p>
            </>
          )}
        </div>
      </Link>
    </li>
  );
}

function TemplateCard({
  template,
  pending,
  onUse,
}: {
  template: WorkflowTemplate;
  pending: boolean;
  onUse: () => void;
}) {
  return (
    <li className="flex flex-col justify-between gap-3 rounded-lg border border-border bg-surface-sunken p-4">
      <div>
        <div className="flex items-center gap-2">
          <WorkflowIcon
            size={15}
            className="text-content-tertiary"
            aria-hidden="true"
          />
          <span className="text-sm font-medium text-content">{template.name}</span>
        </div>
        <p className="mt-1.5 text-xs leading-relaxed text-content-secondary">
          {template.description}
        </p>
      </div>

      <Button
        size="sm"
        icon={<Sparkles size={14} aria-hidden="true" />}
        loading={pending}
        onClick={onUse}
        className="self-start"
      >
        Use this
      </Button>
    </li>
  );
}
