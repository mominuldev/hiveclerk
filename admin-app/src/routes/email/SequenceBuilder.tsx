import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { ArrowLeft, Pause, Play, Plus } from 'lucide-react';
import { StepEditor } from './StepEditor';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Field, Input, Select } from '@/components/ui/Field';
import { Skeleton } from '@/components/ui/Skeleton';
import { toast } from '@/components/ui/Toast';
import {
  useCreateStep,
  useSequence,
  useSequenceStatus,
  useSequences,
  useUpdateSequence,
} from '@/api/queries/useEmail';

/**
 * The sequence builder.
 *
 * ## Why activation refuses out loud
 *
 * The server will not activate a sequence with an empty email or an
 * unapproved AI draft in it, and it says which one. Showing that reason
 * here, on the button, is the difference between an operator fixing it in
 * ten seconds and an operator finding out from a recipient who received a
 * blank message.
 *
 * ## Exit conditions are not the only exit
 *
 * A reply always ends an enrolment and that is not configurable, so the
 * screen says so rather than offering it as a checkbox somebody could
 * turn off. A sequence that kept sending after the person answered is the
 * single most damaging thing this feature could do.
 */
export function SequenceBuilder() {
  const { uuid = '' } = useParams();

  const sequence = useSequence(uuid);
  const vocabulary = useSequences();
  const update = useUpdateSequence(uuid);
  const status = useSequenceStatus(uuid);
  const addStep = useCreateStep(uuid);

  const [name, setName] = useState<string | null>(null);

  if (sequence.isError) {
    return (
      <ErrorNotice error={sequence.error} onRetry={() => void sequence.refetch()} />
    );
  }

  if (sequence.isLoading || !sequence.data) {
    return <Skeleton className="h-96 w-full" />;
  }

  const data = sequence.data;
  const triggers = vocabulary.data?.triggers ?? [];
  const tags = vocabulary.data?.merge_tags ?? [];
  const maxSteps = vocabulary.data?.max_steps ?? 12;

  const setStatus = (action: 'activate' | 'pause'): void => {
    status.mutate(action, {
      onSuccess: () =>
        toast.success(
          action === 'activate' ? 'Sequence is live' : 'Sequence paused',
          action === 'pause'
            ? 'Everybody in it keeps their place and resumes where they stopped.'
            : undefined
        ),
      onError: (error) => toast.error('That did not work', error.message),
    });
  };

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-4">
        <Link
          to="/email/sequences"
          className="inline-flex items-center gap-1.5 text-sm text-content-secondary hover:text-content"
        >
          <ArrowLeft size={14} aria-hidden="true" />
          All sequences
        </Link>

        <div className="flex items-center gap-2">
          <Badge tone={data.status === 'active' ? 'positive' : 'neutral'}>
            {data.status_label}
          </Badge>

          {data.status === 'active' ? (
            <Button
              icon={<Pause size={14} aria-hidden="true" />}
              loading={status.isPending}
              onClick={() => setStatus('pause')}
            >
              Pause
            </Button>
          ) : (
            <Button
              variant="primary"
              icon={<Play size={14} aria-hidden="true" />}
              loading={status.isPending}
              disabled={!data.can_activate}
              onClick={() => setStatus('activate')}
            >
              Activate
            </Button>
          )}
        </div>
      </div>

      {data.blockers.length > 0 && data.status !== 'active' && (
        <div className="rounded-lg border border-border bg-surface-sunken p-3">
          <p className="text-sm font-medium text-content">
            Not ready to activate
          </p>
          <ul className="mt-1.5 space-y-1">
            {data.blockers.map((blocker) => (
              <li
                key={blocker.position}
                className="text-xs leading-relaxed text-content-secondary"
              >
                Email {blocker.position + 1}: {blocker.reason}
              </li>
            ))}
          </ul>
        </div>
      )}

      <Card title="Settings">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Name">
            {({ id }) => (
              <Input
                id={id}
                value={name ?? data.name}
                onChange={(event) => setName(event.target.value)}
                onBlur={() => {
                  if (name !== null && name !== data.name && name.trim() !== '') {
                    update.mutate({ name });
                  }
                }}
              />
            )}
          </Field>

          <Field
            label="Enrol when"
            hint="A lead is only ever enrolled once in a sequence, however many times the trigger fires."
          >
            {({ id, describedBy }) => (
              <Select
                id={id}
                aria-describedby={describedBy}
                value={data.trigger}
                onChange={(event) => update.mutate({ trigger: event.target.value })}
              >
                {triggers.map((trigger) => (
                  <option key={trigger.value} value={trigger.value}>
                    {trigger.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>

          {data.trigger === 'score_threshold' && (
            <Field label="Score reaches">
              {({ id }) => (
                <Input
                  id={id}
                  type="number"
                  min={0}
                  max={100}
                  className="max-w-24"
                  defaultValue={data.threshold}
                  onBlur={(event) =>
                    update.mutate({ threshold: Number(event.target.value) })
                  }
                />
              )}
            </Field>
          )}

          <Field
            label="Sender"
            hint="Left blank, email goes out as whatever this site already sends everything else as — which is the address whose deliverability is already established."
          >
            {({ id, describedBy }) => (
              <Input
                id={id}
                aria-describedby={describedBy}
                type="email"
                placeholder="Use the site default"
                defaultValue={data.from_email ?? ''}
                onBlur={(event) =>
                  update.mutate({ from_email: event.target.value })
                }
              />
            )}
          </Field>
        </div>

        <p className="mt-4 border-t border-border pt-3 text-xs leading-relaxed text-content-tertiary">
          A reply always ends the sequence for that person, and an unsubscribe
          removes them from every sequence at once. Neither is optional.
        </p>
      </Card>

      {data.steps.length === 0 ? (
        <Card>
          <EmptyState
            bare
            title="No emails yet"
            description="One short email that lands two days after the conversation does more than five that arrive over a fortnight. Start there."
            action={
              <Button
                variant="primary"
                loading={addStep.isPending}
                onClick={() => addStep.mutate({})}
              >
                Add the first email
              </Button>
            }
          />
        </Card>
      ) : (
        <div className="space-y-4">
          {data.steps.map((step, index) => (
            <StepEditor
              key={step.id ?? index}
              sequenceUuid={uuid}
              step={step}
              index={index}
              tags={tags}
            />
          ))}

          <Button
            icon={<Plus size={15} aria-hidden="true" />}
            loading={addStep.isPending}
            disabled={data.steps.length >= maxSteps}
            onClick={() => addStep.mutate({})}
          >
            Add another email
          </Button>
        </div>
      )}
    </div>
  );
}
