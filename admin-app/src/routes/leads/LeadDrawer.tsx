import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ScoreBreakdown } from './ScoreBreakdown';
import { LeadTimeline } from './LeadTimeline';
import { Button } from '@/components/ui/Button';
import { Drawer } from '@/components/ui/Drawer';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Field, Input, Select, Textarea } from '@/components/ui/Field';
import { Skeleton } from '@/components/ui/Skeleton';
import { toast } from '@/components/ui/Toast';
import { formatTimestamp } from '@/lib/format';
import {
  useAdjustScore,
  useLead,
  useLeadNote,
  useMoveLead,
  useStages,
  useUpdateLead,
} from '@/api/queries/useLeads';

const STATUSES = [
  'new',
  'contacted',
  'qualified',
  'unqualified',
  'converted',
  'lost',
] as const;

interface LeadDrawerProps {
  uuid: string | null;
  onClose: () => void;
}

/**
 * One lead, in full (D11 §6.2).
 *
 * The Stage dropdown here is not a convenience — it is the keyboard route
 * to the move that the board offers as a drag, and the drag has no
 * keyboard equivalent. Losing this control would make the pipeline
 * pointer-only.
 */
export function LeadDrawer({ uuid, onClose }: LeadDrawerProps) {
  const lead = useLead(uuid);
  const stages = useStages();

  return (
    <Drawer
      open={uuid !== null}
      onClose={onClose}
      title={lead.data?.name ?? 'Lead'}
      {...(lead.data?.company ? { subtitle: lead.data.company } : {})}
      width="lg"
    >
      {lead.isPending && uuid !== null ? (
        <div className="space-y-3">
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-40 w-full" />
        </div>
      ) : lead.isError ? (
        <ErrorNotice error={lead.error} onRetry={() => void lead.refetch()} />
      ) : lead.data ? (
        <Body lead={lead.data} stages={stages.data?.stages ?? []} />
      ) : null}
    </Drawer>
  );
}

function Body({
  lead,
  stages,
}: {
  lead: NonNullable<ReturnType<typeof useLead>['data']>;
  stages: Array<{ id: number; name: string }>;
}) {
  const update = useUpdateLead(lead.uuid);
  const move = useMoveLead();
  const adjust = useAdjustScore(lead.uuid);
  const note = useLeadNote(lead.uuid);

  const [points, setPoints] = useState('');
  const [reason, setReason] = useState('');
  const [body, setBody] = useState('');

  return (
    <div className="space-y-6">
      <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
        <Detail label="Email" value={lead.email} />
        <Detail label="Phone" value={lead.phone} />
        <Detail label="Job title" value={lead.job_title} />
        <Detail label="Captured by" value={lead.source} />
        <Detail
          label="First seen"
          value={lead.first_seen_at ? formatTimestamp(lead.first_seen_at) : null}
        />
        <Detail
          label="Last active"
          value={
            lead.last_active_at ? formatTimestamp(lead.last_active_at) : null
          }
        />
      </dl>

      <div className="grid grid-cols-2 gap-3">
        <Field label="Stage">
          {({ id }) => (
            <Select
              id={id}
              value={lead.stage_id ?? ''}
              onChange={(event) =>
                move.mutate(
                  {
                    uuid: lead.uuid,
                    stageId: event.target.value
                      ? Number(event.target.value)
                      : null,
                  },
                  {
                    onError: (error) =>
                      toast.error('That lead did not move', error.message),
                  }
                )
              }
            >
              <option value="">Unassigned</option>
              {stages.map((stage) => (
                <option key={stage.id} value={stage.id}>
                  {stage.name}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <Field
          label="Status"
          hint="What the rest of the plugin reasons about, whatever your columns are called."
        >
          {({ id }) => (
            <Select
              id={id}
              value={lead.status}
              onChange={(event) =>
                update.mutate(
                  { status: event.target.value },
                  {
                    onError: (error) =>
                      toast.error('That did not save', error.message),
                  }
                )
              }
            >
              {STATUSES.map((status) => (
                <option key={status} value={status}>
                  {status.charAt(0).toUpperCase() + status.slice(1)}
                </option>
              ))}
            </Select>
          )}
        </Field>
      </div>

      {Object.keys(lead.custom_fields).length > 0 && (
        <section className="space-y-2">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-content-secondary">
            Qualification
          </h3>
          <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            {Object.entries(lead.custom_fields).map(([key, value]) => (
              <Detail
                key={key}
                label={key.replace(/_/g, ' ')}
                value={String(value)}
              />
            ))}
          </dl>
        </section>
      )}

      <ScoreBreakdown lead={lead} />

      <section className="space-y-2">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-content-secondary">
          Adjust by hand
        </h3>
        <div className="flex items-end gap-2">
          <Input
            type="number"
            aria-label="Points"
            placeholder="±10"
            value={points}
            onChange={(event) => setPoints(event.target.value)}
            className="w-24"
          />
          <Input
            aria-label="Why"
            placeholder="Why — this becomes the breakdown line"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
          />
          <Button
            variant="primary"
            disabled={Number(points) === 0 || Number.isNaN(Number(points))}
            loading={adjust.isPending}
            onClick={() =>
              adjust.mutate(
                {
                  points: Number(points),
                  ...(reason.trim() ? { reason: reason.trim() } : {}),
                },
                {
                  onSuccess: () => {
                    setPoints('');
                    setReason('');
                  },
                  onError: (error) =>
                    toast.error('That adjustment did not save', error.message),
                }
              )
            }
          >
            Apply
          </Button>
        </div>
      </section>

      {lead.conversations.length > 0 && (
        <section className="space-y-2">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-content-secondary">
            Conversations
          </h3>
          <ul className="space-y-1.5 text-sm">
            {lead.conversations.map((conversation) => (
              <li key={conversation.uuid}>
                <Link
                  to={`/conversations?open=${conversation.uuid}`}
                  className="text-accent-text underline-offset-2 hover:underline"
                >
                  {conversation.started_at
                    ? formatTimestamp(conversation.started_at)
                    : 'Conversation'}{' '}
                  · {conversation.message_count} messages
                </Link>
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="space-y-2">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-content-secondary">
          Timeline
        </h3>

        <Textarea
          aria-label="Add a note"
          rows={2}
          placeholder="Add a note for whoever picks this up next"
          value={body}
          onChange={(event) => setBody(event.target.value)}
        />

        <Button
          size="sm"
          disabled={body.trim() === ''}
          loading={note.isPending}
          onClick={() =>
            note.mutate(
              { body: body.trim() },
              {
                onSuccess: () => setBody(''),
                onError: (error) =>
                  toast.error('That note did not save', error.message),
              }
            )
          }
        >
          Add note
        </Button>

        <div className="pt-2">
          <LeadTimeline entries={lead.timeline} />
        </div>
      </section>
    </div>
  );
}

function Detail({ label, value }: { label: string; value: string | null }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-content-tertiary">
        {label}
      </dt>
      {/* An em dash rather than a blank: a missing value should read as
          "nobody has told us", not as a rendering failure. */}
      <dd className="truncate text-content">{value ?? '—'}</dd>
    </div>
  );
}
