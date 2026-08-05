import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/Button';
import { Field, Input, Select } from '@/components/ui/Field';
import { Skeleton } from '@/components/ui/Skeleton';
import { toast } from '@/components/ui/Toast';
import { useAgent, useAgentAction, useUpdateAgent } from '@/api/queries/useAgents';
import type { StepProps } from './steps';
import { cn } from '@/lib/cn';

const POSITIONS = [
  { value: 'bottom-right', label: 'Bottom right' },
  { value: 'bottom-left', label: 'Bottom left' },
] as const;

/* -------------------------------------------------------------------------
 * Step 4 — Look
 * ---------------------------------------------------------------------- */

/**
 * The widget's colour, corner and greeting, shown as it will appear.
 *
 * A preview rather than a form with a Save button, because everything on
 * this step is visual and a field labelled "accent" tells an operator
 * nothing about what their visitors will see.
 */
export function StepLook({ onDone, agentUuid, busy }: StepProps) {
  const { data: agent, isPending } = useAgent(agentUuid);
  const update = useUpdateAgent(agentUuid ?? '');

  const [accent, setAccent] = useState('#3b5bdb');
  const [position, setPosition] = useState<string>('bottom-right');
  const [greeting, setGreeting] = useState('');
  const [seeded, setSeeded] = useState(false);

  if (isPending || !agent) {
    return <Skeleton className="h-72 w-full rounded-xl" />;
  }

  if (!seeded) {
    setSeeded(true);
    setGreeting(agent.greeting ?? '');

    const config = agent.widget_config as Record<string, unknown> | undefined;

    if (typeof config?.accent === 'string') {
      setAccent(config.accent);
    }

    if (typeof config?.position === 'string') {
      setPosition(config.position);
    }
  }

  const save = () => {
    update.mutate(
      {
        greeting,
        widget_config: { accent, position },
      },
      {
        onSuccess: () => onDone(),
        onError: (error) => toast.error(error.message),
      }
    );
  };

  return (
    <div className="mx-auto max-w-3xl">
      <h2 className="text-center font-display text-xl font-bold tracking-[-0.02em] text-content">
        How should {agent.name} look?
      </h2>

      <div className="mt-6 grid gap-6 md:grid-cols-2">
        <div className="space-y-4">
          <Field label="Accent colour" hint="Used for the launcher and the visitor's own messages.">
            {({ id, describedBy }) => (
              <div className="flex items-center gap-2">
                <input
                  type="color"
                  value={accent}
                  aria-label="Pick an accent colour"
                  onChange={(event) => setAccent(event.target.value)}
                  className="h-9 w-10 cursor-pointer rounded-lg border border-border bg-surface p-1"
                />
                <Input
                  id={id}
                  mono
                  aria-describedby={describedBy}
                  value={accent}
                  onChange={(event) => setAccent(event.target.value)}
                />
              </div>
            )}
          </Field>

          <Field label="Where it sits">
            {({ id }) => (
              <Select
                id={id}
                value={position}
                onChange={(event) => setPosition(event.target.value)}
              >
                {POSITIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>

          <Field
            label="Opening line"
            hint="The first thing a visitor reads. A question gets more replies than a greeting."
          >
            {({ id, describedBy }) => (
              <Input
                id={id}
                aria-describedby={describedBy}
                value={greeting}
                onChange={(event) => setGreeting(event.target.value)}
              />
            )}
          </Field>
        </div>

        {/* A real preview of the two things this step changes. Not a
            screenshot of the customer's homepage — that needs a headless
            browser on their server, which is not a dependency this
            product takes for a colour picker. */}
        <div className="rounded-xl border border-border bg-surface-sunken p-4">
          <p className="mb-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-content-tertiary">
            Preview
          </p>

          <div
            className={cn(
              'flex flex-col gap-2 rounded-xl border border-border bg-surface p-3',
              'bottom-left' === position ? 'items-start' : 'items-end'
            )}
          >
            <div className="max-w-[85%] self-start rounded-xl rounded-bl-sm bg-surface-sunken px-3 py-2 text-sm text-content">
              {greeting || 'Hi — what can I help you find?'}
            </div>

            <div
              className="max-w-[85%] rounded-xl rounded-br-sm px-3 py-2 text-sm text-white"
              style={{ backgroundColor: accent }}
            >
              Do you ship to Ireland?
            </div>

            <span
              aria-hidden="true"
              className="mt-2 flex h-10 w-10 items-center justify-center rounded-full text-white shadow-md"
              style={{ backgroundColor: accent }}
            >
              ●
            </span>
          </div>
        </div>
      </div>

      <div className="mt-6 flex justify-end">
        <Button
          variant="primary"
          loading={update.isPending || busy}
          onClick={save}
        >
          Continue
        </Button>
      </div>
    </div>
  );
}

/* -------------------------------------------------------------------------
 * Step 5 — Publish
 * ---------------------------------------------------------------------- */

/**
 * Put the clerk on duty (D11 §12).
 *
 * The clerk is shown working before the commitment is asked for — the
 * test console is one click away, and the button says what it does rather
 * than "Finish".
 */
export function StepPublish({ onDone, agentUuid, busy }: StepProps) {
  const { data: agent, isPending } = useAgent(agentUuid);
  const publish = useAgentAction(agentUuid ?? '', 'publish');

  if (isPending || !agent) {
    return <Skeleton className="h-56 w-full rounded-xl" />;
  }

  const live = 'published' === agent.status;

  return (
    <div className="mx-auto max-w-lg text-center">
      <h2 className="font-display text-xl font-bold tracking-[-0.02em] text-content">
        {live ? `${agent.name} is on duty.` : `Put ${agent.name} on duty?`}
      </h2>

      <p className="mt-2 text-sm leading-relaxed text-content-secondary">
        {live
          ? 'Visitors can talk to them now. Everything below is still editable at any time.'
          : 'Publishing shows the widget to visitors on the pages this clerk covers. Nothing about your site changes until you do.'}
      </p>

      <div className="mt-6 rounded-xl border border-border bg-surface p-4 text-left">
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between gap-4">
            <dt className="text-content-tertiary">Clerk</dt>
            <dd className="text-content">{agent.name}</dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="text-content-tertiary">Role</dt>
            <dd className="text-content">{agent.role_label}</dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="text-content-tertiary">Knowledge sources</dt>
            <dd className="font-mono tabular-nums text-content">
              {agent.source_count}
            </dd>
          </div>
        </dl>

        {0 === agent.source_count && (
          <p className="mt-3 rounded-lg border border-border bg-surface-sunken px-3 py-2 text-xs leading-relaxed text-content-secondary">
            No knowledge is attached yet. This clerk will answer from the model
            alone and hand over when it is unsure. You can add sources whenever
            you like.
          </p>
        )}
      </div>

      <div className="mt-6 flex items-center justify-center gap-2">
        <Link to={`/clerks/${agent.uuid}`}>
          <Button>Try them first</Button>
        </Link>

        <Button
          variant="primary"
          loading={publish.isPending || busy}
          onClick={() => {
            if (live) {
              onDone();
              return;
            }

            publish.mutate(undefined, {
              onSuccess: () => onDone(),
              onError: (error) => toast.error(error.message),
            });
          }}
        >
          {live ? 'Finish setup' : `Put ${agent.name} on duty`}
        </Button>
      </div>
    </div>
  );
}
