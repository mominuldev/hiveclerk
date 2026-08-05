import { useEffect, useState } from 'react';
import { ArrowRight, Lock } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Field, Input, Select } from '@/components/ui/Field';
import { Skeleton } from '@/components/ui/Skeleton';
import { toast } from '@/components/ui/Toast';
import {
  useMapping,
  useMappingFields,
  useSaveMapping,
  type IntegrationCard,
  type MappingState,
} from '@/api/queries/useIntegrations';

interface FieldMappingProps {
  card: IntegrationCard;
  events: string[];
}

const TRIGGERS = [
  { value: 'captured', label: 'Lead captured' },
  { value: 'qualified', label: 'Lead qualified' },
  { value: 'score_above', label: 'Score above' },
  { value: 'stage_moved', label: 'Stage changed' },
  { value: 'manual', label: 'Only by hand' },
] as const;

/**
 * The mapping panel from D11 §8.
 *
 * ## Email is a locked row, not a disabled dropdown
 *
 * Every destination here identifies a contact by address, so there is no
 * choice to offer. It renders as a row with the word "locked" beside it —
 * which explains the constraint — rather than as a greyed-out select that
 * reads as something broken.
 *
 * ## The transcript row warns before it is chosen, not after
 *
 * Mapping the transcript copies a visitor's whole conversation into a
 * third-party SaaS. That is a decision worth stating at the moment it is
 * made, on the row where it is made.
 */
export function FieldMapping({ card, events }: FieldMappingProps) {
  const mapping = useMapping(card.id);
  const fields = useMappingFields(card.id);
  const save = useSaveMapping(card.id);

  const [draft, setDraft] = useState<MappingState | null>(null);

  useEffect(() => {
    if (mapping.data) {
      setDraft(mapping.data);
    }
  }, [mapping.data]);

  if (mapping.isError) {
    return (
      <ErrorNotice error={mapping.error} onRetry={() => void mapping.refetch()} />
    );
  }

  if (!draft) {
    return <Skeleton className="h-72 w-full" />;
  }

  const targets = fields.data?.targets ?? [];
  const sources = fields.data?.sources ?? [];

  const setPair = (source: string, target: string): void => {
    const next = { ...draft.mapping };

    if (target === '') {
      delete next[source];
    } else {
      next[source] = target;
    }

    setDraft({ ...draft, mapping: next });
  };

  const commit = (): void => {
    save.mutate(
      {
        mapping: draft.mapping,
        trigger: draft.trigger,
        threshold: draft.threshold,
        send_transcript: draft.send_transcript,
        events: draft.events,
      },
      {
        onSuccess: (saved) => {
          setDraft(saved);
          toast.success('Mapping saved');
        },
        onError: (error) => toast.error('That did not save', error.message),
      }
    );
  };

  return (
    <Card
      title={`Field mapping · ${card.name}`}
      actions={
        <Button variant="primary" loading={save.isPending} onClick={commit}>
          Save
        </Button>
      }
    >
      {fields.isLoading && (
        <p className="mb-4 text-xs text-content-tertiary">
          Reading the field list from {card.name}…
        </p>
      )}

      <div className="space-y-1">
        <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-3 pb-2 text-[10px] font-semibold uppercase tracking-[0.12em] text-content-tertiary">
          <span>Hiveclerk</span>
          <span aria-hidden="true" />
          <span>{card.name}</span>
        </div>

        {sources.map((source) => {
          const value = draft.mapping[source.key] ?? '';

          return (
            <div
              key={source.key}
              className="grid grid-cols-[1fr_auto_1fr] items-center gap-3 border-b border-border py-2 last:border-0"
            >
              <div className="min-w-0">
                <span className="text-sm text-content">{source.label}</span>
                {source.sensitive && draft.mapping[source.key] && (
                  <p className="mt-0.5 text-[11px] leading-snug text-warning">
                    The whole conversation leaves this server when this is
                    mapped.
                  </p>
                )}
              </div>

              <ArrowRight
                size={13}
                aria-hidden="true"
                className="text-content-tertiary"
              />

              {source.locked ? (
                <span className="flex items-center gap-1.5 text-sm text-content-secondary">
                  <Lock size={12} aria-hidden="true" className="text-content-tertiary" />
                  {targets.find((target) => target.locked)?.label ?? 'Email'}
                  <Badge>locked</Badge>
                </span>
              ) : (
                <Select
                  aria-label={`Map ${source.label} to`}
                  value={value}
                  onChange={(event) => setPair(source.key, event.target.value)}
                >
                  <option value="">Not mapped</option>
                  {targets
                    .filter((target) => !target.locked)
                    .map((target) => (
                      <option key={target.key} value={target.key}>
                        {target.custom ? `Custom: ${target.label}` : target.label}
                      </option>
                    ))}
                </Select>
              )}
            </div>
          );
        })}
      </div>

      <div className="mt-6 space-y-4 border-t border-border pt-5">
        <Field
          label="Push when"
          hint="Qualified by default. A CRM that receives every visitor who typed an address becomes a list nobody trusts — and most of them charge per contact."
        >
          {({ id, describedBy }) => (
            <div className="flex flex-wrap items-center gap-2">
              <Select
                id={id}
                aria-describedby={describedBy}
                className="max-w-56"
                value={draft.trigger}
                onChange={(event) =>
                  setDraft({ ...draft, trigger: event.target.value })
                }
              >
                {TRIGGERS.map((trigger) => (
                  <option key={trigger.value} value={trigger.value}>
                    {trigger.label}
                  </option>
                ))}
              </Select>

              {draft.trigger === 'score_above' && (
                <Input
                  type="number"
                  min={0}
                  max={100}
                  aria-label="Score threshold"
                  className="max-w-24"
                  value={draft.threshold}
                  onChange={(event) =>
                    setDraft({ ...draft, threshold: Number(event.target.value) })
                  }
                />
              )}
            </div>
          )}
        </Field>

        {card.id === 'webhook' && (
          <fieldset>
            <legend className="text-sm font-medium text-content">
              Events to send
            </legend>
            <p className="mb-2 mt-1 text-xs leading-relaxed text-content-tertiary">
              Conversation events fire on every conversation on the site. Turn
              them on only if your endpoint expects that volume.
            </p>

            <div className="grid gap-1.5 sm:grid-cols-2">
              {events.map((event) => (
                <label key={event} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="size-3.5 rounded border-border accent-[var(--hvc-accent)]"
                    checked={draft.events.includes(event)}
                    onChange={(input) =>
                      setDraft({
                        ...draft,
                        events: input.target.checked
                          ? [...draft.events, event]
                          : draft.events.filter((name) => name !== event),
                      })
                    }
                  />
                  <span className="font-mono text-[12px] text-content-secondary">
                    {event}
                  </span>
                </label>
              ))}
            </div>
          </fieldset>
        )}
      </div>
    </Card>
  );
}
