import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Plus, ShieldOff } from 'lucide-react';
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
  useCreateSequence,
  useSequences,
  type Sequence,
  type SequenceStatus,
} from '@/api/queries/useEmail';

const TONES: Record<SequenceStatus, 'positive' | 'neutral' | 'warning'> = {
  active: 'positive',
  draft: 'neutral',
  paused: 'warning',
  archived: 'neutral',
};

/**
 * The sequence list.
 *
 * ## Numbers only where there are numbers
 *
 * A sequence that has enrolled nobody says so in words rather than
 * showing "0 enrolled · 0 sent · 0% opened". An honest empty state beats
 * a row of zeros that reads like the feature is broken — and open rates
 * are absent entirely, because this sprint does not track opens and a
 * column of dashes is more truthful than a column of 0%.
 */
export function Sequences() {
  const list = useSequences();
  const create = useCreateSequence();

  const [naming, setNaming] = useState(false);
  const [name, setName] = useState('');

  if (list.isError) {
    return <ErrorNotice error={list.error} onRetry={() => void list.refetch()} />;
  }

  if (list.isLoading || !list.data) {
    return <Skeleton className="h-64 w-full" />;
  }

  const submit = (): void => {
    create.mutate(
      { name },
      {
        onSuccess: () => {
          toast.success('Sequence created', 'It is a draft until you activate it.');
          setNaming(false);
          setName('');
        },
        onError: (error) => toast.error('That did not save', error.message),
      }
    );
  };

  return (
    <div className="space-y-5">
      <Card
        title="Follow-up sequences"
        actions={
          <Button
            variant="primary"
            icon={<Plus size={15} aria-hidden="true" />}
            onClick={() => setNaming(true)}
          >
            New sequence
          </Button>
        }
      >
        {list.data.sequences.length === 0 ? (
          <EmptyState
            bare
            title="No sequences yet"
            description="A sequence sends a few short emails to a lead over a few days, and stops the moment they reply. Start with one email and add more once you can see it working."
            action={
              <Button variant="primary" onClick={() => setNaming(true)}>
                Build the first one
              </Button>
            }
          />
        ) : (
          <ul className="divide-y divide-border">
            {list.data.sequences.map((sequence) => (
              <SequenceRow key={sequence.uuid} sequence={sequence} />
            ))}
          </ul>
        )}
      </Card>

      {list.data.suppressed > 0 && (
        <p className="flex items-center gap-2 text-xs text-content-tertiary">
          <ShieldOff size={13} aria-hidden="true" />
          {list.data.suppressed}{' '}
          {list.data.suppressed === 1 ? 'address has' : 'addresses have'} asked
          not to be emailed. No sequence will write to them.
        </p>
      )}

      <Modal
        open={naming}
        onClose={() => setNaming(false)}
        title="New sequence"
        description="Name it for what it does — you will be choosing when it enrols people next."
        footer={
          <>
            <Button onClick={() => setNaming(false)}>Cancel</Button>
            <Button
              variant="primary"
              loading={create.isPending}
              disabled={name.trim() === ''}
              onClick={submit}
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
              placeholder="Qualified lead follow-up"
              onChange={(event) => setName(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter' && name.trim() !== '') {
                  submit();
                }
              }}
            />
          )}
        </Field>
      </Modal>
    </div>
  );
}

function SequenceRow({ sequence }: { sequence: Sequence }) {
  const sent = sequence.stats.sent ?? 0;
  const active = sequence.stats.active ?? 0;

  return (
    <li>
      <Link
        to={`/email/sequences/${sequence.uuid}`}
        className="flex items-center justify-between gap-4 py-3 transition-colors hover:bg-surface-hover"
      >
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <span className="truncate text-sm font-medium text-content">
              {sequence.name}
            </span>
            <Badge tone={TONES[sequence.status]}>{sequence.status_label}</Badge>
          </div>

          <p className="mt-0.5 text-xs text-content-tertiary">
            {sequence.trigger_label} ·{' '}
            {sequence.steps === 0
              ? 'no emails yet'
              : `${sequence.steps} ${sequence.steps === 1 ? 'email' : 'emails'}`}
          </p>
        </div>

        <div className="shrink-0 text-right">
          {sequence.enrolled === 0 ? (
            <span className="text-xs text-content-tertiary">
              Nobody enrolled yet
            </span>
          ) : (
            <>
              <span className="font-mono text-[13px] tabular-nums text-content">
                {sequence.enrolled}
              </span>
              <span className="ml-1 text-xs text-content-tertiary">enrolled</span>
              <p className="text-[11px] text-content-tertiary">
                {active} in progress · {sent} sent
              </p>
            </>
          )}
        </div>
      </Link>
    </li>
  );
}
