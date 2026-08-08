import { Card } from '@/components/ui/Card';
import { Field, Input, Select } from '@/components/ui/Field';
import type { Vocabulary, WorkflowDetail } from '@/api/queries/useWorkflows';

interface TriggerSettingsProps {
  workflow: WorkflowDetail;
  vocabulary: Vocabulary;
  onChange: (changes: {
    name?: string;
    trigger?: string;
    trigger_config?: Record<string, unknown>;
    runs_once?: boolean;
  }) => void;
}

/**
 * Name, trigger, and the settings that trigger needs (FR-WFL-01).
 *
 * ## The fields change with the trigger, because the questions do
 *
 * A stage trigger needs to know which stage. A schedule needs an interval
 * and — non-negotiably — a filter, because "every lead, every day" is a
 * configuration that exists only to be regretted. Showing all of the
 * fields all of the time would mean three quarters of this card is always
 * irrelevant, and an irrelevant field is one somebody fills in.
 *
 * ## Runs once is a checkbox and it defaults to on
 *
 * A lead whose stage changes four times in an afternoon fires the stage
 * trigger four times. The default answer to "should they go through this
 * four times" is no, and turning it off is a deliberate act on a control
 * that says what it costs.
 */
export function TriggerSettings({
  workflow,
  vocabulary,
  onChange,
}: TriggerSettingsProps) {
  const trigger = vocabulary.triggers.find((t) => t.value === workflow.trigger);
  const segment = workflow.segment as Record<string, unknown>;

  const setConfig = (patch: Record<string, unknown>): void => {
    onChange({ trigger_config: { ...workflow.trigger_config, ...patch } });
  };

  const setSegment = (patch: Record<string, unknown>): void => {
    setConfig({ segment: { ...segment, ...patch } });
  };

  return (
    <Card title="Settings">
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Name">
          {({ id }) => (
            <Input
              id={id}
              defaultValue={workflow.name}
              onBlur={(event) => {
                if (
                  event.target.value.trim() !== '' &&
                  event.target.value !== workflow.name
                ) {
                  onChange({ name: event.target.value });
                }
              }}
            />
          )}
        </Field>

        <Field
          label="Start when"
          {...(trigger ? { hint: trigger.description } : {})}
        >
          {({ id, describedBy }) => (
            <Select
              id={id}
              aria-describedby={describedBy}
              value={workflow.trigger}
              onChange={(event) => onChange({ trigger: event.target.value })}
            >
              {vocabulary.triggers.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          )}
        </Field>

        {trigger?.needs_stage === true && (
          <Field
            label="Stage"
            hint="Left unset, any stage change starts this workflow."
          >
            {({ id, describedBy }) => (
              <Select
                id={id}
                aria-describedby={describedBy}
                value={String(workflow.trigger_config.stage_id ?? '')}
                onChange={(event) =>
                  setConfig({ stage_id: Number(event.target.value) })
                }
              >
                <option value="">Any stage</option>
                {vocabulary.stages.map((stage) => (
                  <option key={stage.id} value={stage.id}>
                    {stage.name}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        )}

        {trigger?.scheduled === true && (
          <>
            <Field
              label="How often"
              hint={`At most once an hour. Hiveclerk takes up to ${Math.max(
                1,
                Math.round(workflow.interval / 60)
              )} hours of leads at a time.`}
            >
              {({ id, describedBy }) => (
                <Select
                  id={id}
                  aria-describedby={describedBy}
                  value={String(workflow.interval)}
                  onChange={(event) =>
                    setConfig({ interval: Number(event.target.value) })
                  }
                >
                  <option value={String(vocabulary.min_interval)}>
                    Every hour
                  </option>
                  <option value="360">Every 6 hours</option>
                  <option value="1440">Once a day</option>
                  <option value="10080">Once a week</option>
                </Select>
              )}
            </Field>

            <Field
              label="Only leads in"
              hint="A scheduled workflow needs a filter. Without one it would run against every lead you have."
            >
              {({ id, describedBy }) => (
                <Select
                  id={id}
                  aria-describedby={describedBy}
                  value={String(segment.stage_id ?? '')}
                  onChange={(event) =>
                    setSegment({
                      stage_id:
                        event.target.value === '' ? undefined : event.target.value,
                    })
                  }
                >
                  <option value="">Any stage</option>
                  {vocabulary.stages.map((stage) => (
                    <option key={stage.id} value={stage.id}>
                      {stage.name}
                    </option>
                  ))}
                </Select>
              )}
            </Field>

            <Field label="Minimum score">
              {({ id }) => (
                <Input
                  id={id}
                  type="number"
                  min={0}
                  max={100}
                  className="max-w-28"
                  defaultValue={String(segment.min_score ?? 0)}
                  onBlur={(event) =>
                    setSegment({ min_score: Number(event.target.value) })
                  }
                />
              )}
            </Field>

            <label className="flex items-start gap-2.5 text-sm text-content">
              <input
                type="checkbox"
                className="mt-0.5 size-4 rounded border-border accent-[var(--hvc-accent)]"
                checked={segment.has_email === true}
                onChange={(event) =>
                  setSegment({ has_email: event.target.checked })
                }
              />
              <span>
                Only leads with an email address
                <span className="mt-0.5 block text-xs text-content-tertiary">
                  Anyone without one cannot receive email, so a workflow that
                  ends in a sequence would open runs that go nowhere.
                </span>
              </span>
            </label>
          </>
        )}
      </div>

      <label className="mt-4 flex items-start gap-2.5 border-t border-border pt-3 text-sm text-content">
        <input
          type="checkbox"
          className="mt-0.5 size-4 rounded border-border accent-[var(--hvc-accent)]"
          checked={workflow.runs_once}
          onChange={(event) => onChange({ runs_once: event.target.checked })}
        />
        <span>
          Each lead goes through this once
          <span className="mt-0.5 block text-xs leading-relaxed text-content-tertiary">
            Off, a lead whose stage changes four times in an afternoon goes
            through four times — and receives whatever this workflow sends four
            times with it.
          </span>
        </span>
      </label>
    </Card>
  );
}
