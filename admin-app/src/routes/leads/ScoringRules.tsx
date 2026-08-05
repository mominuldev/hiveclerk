import { useEffect, useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { StagesEditor } from './StagesEditor';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Field, Input, Select } from '@/components/ui/Field';
import { Skeleton } from '@/components/ui/Skeleton';
import { toast } from '@/components/ui/Toast';
import {
  useSaveScoringPolicy,
  useScoringPolicy,
  type ScoringPolicy,
  type ScoringRule,
} from '@/api/queries/useLeads';

/**
 * The scoring policy (FR-LED-03) and the pipeline's columns (FR-LED-05).
 *
 * Edited as one form and saved in one request. Rules, band boundaries and
 * the alert threshold are three views of the same decision — "what counts
 * as a lead worth calling" — and saving them separately would let a
 * customer raise the qualified boundary above the alert threshold and
 * quietly stop being told about anything.
 *
 * The vocabulary in every dropdown comes from the server rather than from
 * a table in this file. Two lists of operators that drift apart produce a
 * rule the editor offers and the engine does not understand.
 */
export function ScoringRules() {
  const policy = useScoringPolicy();
  const save = useSaveScoringPolicy();

  const [draft, setDraft] = useState<ScoringPolicy | null>(null);

  useEffect(() => {
    if (policy.data && draft === null) {
      setDraft(policy.data);
    }
  }, [policy.data, draft]);

  if (policy.isError) {
    return <ErrorNotice error={policy.error} onRetry={() => void policy.refetch()} />;
  }

  if (!draft) {
    return <Skeleton className="h-96 w-full" />;
  }

  const patch = (index: number, changes: Partial<ScoringRule>): void => {
    setDraft((current) =>
      current
        ? {
            ...current,
            rules: current.rules.map((rule, position) =>
              position === index ? { ...rule, ...changes } : rule
            ),
          }
        : current
    );
  };

  const commit = (): void => {
    save.mutate(
      { rules: draft.rules, bands: draft.bands, alerts: draft.alerts },
      {
        onSuccess: (saved) => {
          setDraft(saved);
          toast.success('Scoring saved');
        },
        onError: (error) => toast.error('That did not save', error.message),
      }
    );
  };

  return (
    <div className="space-y-5">
      <Card
        title="Scoring rules"
        actions={
          <div className="flex items-center gap-2">
            <Button
              icon={<Plus size={15} />}
              disabled={draft.rules.length >= draft.max_rules}
              onClick={() =>
                setDraft({
                  ...draft,
                  rules: [
                    ...draft.rules,
                    {
                      id: `rule_${draft.rules.length + 1}_${Date.now()}`,
                      label: '',
                      kind: 'field',
                      operator: 'not_empty',
                      target: 'email',
                      value: '',
                      points: 10,
                      enabled: true,
                      once: true,
                    },
                  ],
                })
              }
            >
              Add rule
            </Button>
            <Button variant="primary" loading={save.isPending} onClick={commit}>
              Save
            </Button>
          </div>
        }
      >
        {!draft.customised && (
          <p className="mb-4 rounded-lg border border-border bg-surface-sunken p-3 text-sm text-content-secondary">
            These are the starting rules, not a policy anyone chose. They are
            already scoring — edit them so they describe your business, and
            they stop being suggestions the moment you save.
          </p>
        )}

        <ul className="space-y-3">
          {draft.rules.map((rule, index) => {
            const kind = draft.kinds.find((entry) => entry.value === rule.kind);
            const operator = kind?.operators.find(
              (entry) => entry.value === rule.operator
            );

            return (
              <li
                key={rule.id}
                className="rounded-lg border border-border p-3"
              >
                {/* Flex-wrap rather than a twelve-column grid. The row
                    holds six controls of genuinely different widths, and
                    a grid that fitted them at this breakpoint crushed the
                    points field to nothing at the next one. */}
                <div className="flex flex-wrap items-center gap-2">
                  <Input
                    aria-label="What the breakdown line reads"
                    placeholder="What this line says on the lead"
                    value={rule.label}
                    onChange={(event) =>
                      patch(index, { label: event.target.value })
                    }
                    className="min-w-[14rem] flex-[3]"
                  />

                  <Select
                    aria-label="What this rule looks at"
                    value={rule.kind}
                    onChange={(event) => {
                      const next = draft.kinds.find(
                        (entry) => entry.value === event.target.value
                      );

                      patch(index, {
                        kind: event.target.value as ScoringRule['kind'],
                        // The operator is reset with the kind. Carrying
                        // "is a business address" onto a page rule would
                        // leave a rule the engine silently never fires.
                        operator: next?.operators[0]?.value ?? 'gte',
                        target: next?.targets[0] ?? '',
                      });
                    }}
                    className="min-w-[9rem] flex-1"
                  >
                    {draft.kinds.map((entry) => (
                      <option key={entry.value} value={entry.value}>
                        {entry.label}
                      </option>
                    ))}
                  </Select>

                  {kind && kind.targets.length > 0 ? (
                    <Select
                      aria-label="Field"
                      value={rule.target}
                      onChange={(event) =>
                        patch(index, { target: event.target.value })
                      }
                      className="min-w-[9rem] flex-1"
                    >
                      {kind.targets.map((target) => (
                        <option key={target} value={target}>
                          {target.replace(/_/g, ' ')}
                        </option>
                      ))}
                    </Select>
                  ) : (
                    <Input
                      aria-label="Target"
                      placeholder={rule.kind === 'page' ? '/pricing*' : ''}
                      disabled={rule.kind === 'keyword'}
                      value={rule.target}
                      onChange={(event) =>
                        patch(index, { target: event.target.value })
                      }
                      className="min-w-[9rem] flex-1"
                    />
                  )}

                  <Select
                    aria-label="Comparison"
                    value={rule.operator}
                    onChange={(event) =>
                      patch(index, { operator: event.target.value })
                    }
                    className="min-w-[10rem] flex-1"
                  >
                    {(kind?.operators ?? []).map((entry) => (
                      <option key={entry.value} value={entry.value}>
                        {entry.label}
                      </option>
                    ))}
                  </Select>

                  <Input
                    aria-label="Value"
                    // Blank rather than a hint when the operator takes no
                    // value. A greyed-out "2" in a disabled box reads as a
                    // setting somebody chose and cannot change.
                    placeholder={
                      operator && !operator.needs_value
                        ? ''
                        : rule.kind === 'keyword'
                          ? 'quote, demo'
                          : '2'
                    }
                    disabled={operator ? !operator.needs_value : false}
                    value={rule.value}
                    onChange={(event) =>
                      patch(index, { value: event.target.value })
                    }
                    className="min-w-[8rem] flex-1"
                  />

                  <div className="flex items-center gap-1">
                    <Input
                      type="number"
                      aria-label="Points"
                      value={rule.points}
                      onChange={(event) =>
                        patch(index, { points: Number(event.target.value) })
                      }
                      className="w-[5.5rem]"
                    />
                    <span className="text-xs text-content-tertiary">pts</span>
                  </div>

                  <Button
                    size="sm"
                    variant="ghost"
                    aria-label={`Remove ${rule.label || 'rule'}`}
                    icon={<Trash2 size={14} />}
                    onClick={() =>
                      setDraft({
                        ...draft,
                        rules: draft.rules.filter(
                          (_entry, position) => position !== index
                        ),
                      })
                    }
                  />
                </div>

                <label className="mt-2 flex items-center gap-2 text-xs text-content-secondary">
                  <input
                    type="checkbox"
                    checked={rule.once}
                    onChange={(event) =>
                      patch(index, { once: event.target.checked })
                    }
                  />
                  Award at most once per lead
                </label>
              </li>
            );
          })}
        </ul>

        <p className="mt-4 text-sm text-content-secondary">
          A lead can score at most{' '}
          <strong className="text-content">
            {draft.rules
              .filter((rule) => rule.enabled && rule.points > 0)
              .reduce((total, rule) => total + rule.points, 0)}
          </strong>{' '}
          under these rules. That number is the denominator on every lead.
        </p>
      </Card>

      <Card title="Bands">
        <div className="grid gap-3 md:grid-cols-3">
          {(['warm', 'hot', 'qualified'] as const).map((band) => (
            <Field
              key={band}
              label={`${band.charAt(0).toUpperCase()}${band.slice(1)} at`}
            >
              {({ id }) => (
                <Input
                  id={id}
                  type="number"
                  value={draft.bands[band]}
                  onChange={(event) =>
                    setDraft({
                      ...draft,
                      bands: { ...draft.bands, [band]: Number(event.target.value) },
                    })
                  }
                />
              )}
            </Field>
          ))}
        </div>
      </Card>

      <Card title="Tell somebody">
        <div className="space-y-3">
          <label className="flex items-center gap-2 text-sm text-content">
            <input
              type="checkbox"
              checked={draft.alerts.enabled}
              onChange={(event) =>
                setDraft({
                  ...draft,
                  alerts: { ...draft.alerts, enabled: event.target.checked },
                })
              }
            />
            Email and Slack when a lead crosses the threshold
          </label>

          <div className="grid gap-3 md:grid-cols-3">
            <Field label="Score">
              {({ id }) => (
                <Input
                  id={id}
                  type="number"
                  value={draft.alerts.score}
                  onChange={(event) =>
                    setDraft({
                      ...draft,
                      alerts: {
                        ...draft.alerts,
                        score: Number(event.target.value),
                      },
                    })
                  }
                />
              )}
            </Field>

            <Field
              label="Email"
              hint="Comma separated. Sent once per lead, ever."
            >
              {({ id }) => (
                <Input
                  id={id}
                  value={draft.alerts.emails.join(', ')}
                  onChange={(event) =>
                    setDraft({
                      ...draft,
                      alerts: {
                        ...draft.alerts,
                        emails: event.target.value
                          .split(',')
                          .map((entry) => entry.trim())
                          .filter(Boolean),
                      },
                    })
                  }
                />
              )}
            </Field>

            <Field label="Slack webhook" hint="An https:// incoming webhook.">
              {({ id }) => (
                <Input
                  id={id}
                  placeholder="https://hooks.slack.com/services/…"
                  value={draft.alerts.slack_webhook ?? ''}
                  onChange={(event) =>
                    setDraft({
                      ...draft,
                      alerts: {
                        ...draft.alerts,
                        slack_webhook: event.target.value,
                      },
                    })
                  }
                />
              )}
            </Field>
          </div>

          <Button variant="primary" loading={save.isPending} onClick={commit}>
            Save
          </Button>
        </div>
      </Card>

      <StagesEditor />
    </div>
  );
}
