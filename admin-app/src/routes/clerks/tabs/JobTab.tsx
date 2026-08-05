import { Field, Input, Select, Textarea } from '@/components/ui/Field';
import { cn } from '@/lib/cn';
import { usePresets, type AgentDetail, type AgentInput } from '@/api/queries/useAgents';
import { useProviderModels, useProviders } from '@/api/queries/useProviders';

interface TabProps {
  agent: AgentDetail;
  onChange: (patch: AgentInput) => void;
}

const MAX_INSTRUCTIONS = 4000;

/**
 * What the clerk is: its name, its job, how it sounds, and which model
 * does the talking.
 */
export function JobTab({ agent, onChange }: TabProps) {
  const presets = usePresets();
  const providers = useProviders();

  const model = agent.model_config as {
    provider?: string;
    model?: string;
    temperature?: number;
    max_tokens?: number;
  };

  const personality = agent.personality as {
    formality?: number;
    verbosity?: number;
  };

  const configured = (providers.data?.providers ?? []).filter((entry) => entry.is_set);
  const provider = model.provider ?? '';

  // Only ask for models once that provider actually has a key stored.
  // Asking otherwise guarantees a 409 and puts an error in the console for
  // something the operator has not done yet.
  const hasKey = configured.some((entry) => entry.provider === provider);
  const models = useProviderModels(provider, hasKey);

  const instructions = agent.instructions ?? '';

  return (
    <div className="space-y-6">
      <Field label="Name" hint="Shown above every reply the visitor reads.">
        {({ id }) => (
          <Input
            id={id}
            value={agent.name}
            onChange={(event) => onChange({ name: event.target.value })}
          />
        )}
      </Field>

      <fieldset>
        <legend className="mb-2 text-sm font-medium text-content">Role</legend>
        <div className="flex flex-wrap gap-2">
          {(presets.data?.presets ?? []).map((preset) => (
            <label
              key={preset.key}
              className={cn(
                'cursor-pointer rounded-lg border px-3 py-1.5 text-sm transition-colors',
                'focus-within:border-accent',
                preset.key === agent.role
                  ? 'border-accent bg-accent-subtle text-content'
                  : 'border-border bg-surface text-content-secondary hover:border-border-strong'
              )}
            >
              <input
                type="radio"
                name="role"
                className="sr-only"
                checked={preset.key === agent.role}
                onChange={() => onChange({ role_preset: preset.key })}
              />
              {preset.label}
            </label>
          ))}
        </div>
        <p className="mt-2 text-xs leading-relaxed text-content-tertiary">
          {/* Changing the role never rewrites what is written below. A
              template that reasserts itself is a template nobody trusts. */}
          Changing the role does not overwrite instructions you have already
          written.
        </p>
      </fieldset>

      <Field
        label="What this clerk does"
        hint="Written in the second person, as if you were briefing a new colleague. Say what to do, not what to avoid."
      >
        {({ id, describedBy }) => (
          <>
            <Textarea
              id={id}
              rows={10}
              value={instructions}
              maxLength={MAX_INSTRUCTIONS}
              aria-describedby={describedBy}
              onChange={(event) => onChange({ instructions: event.target.value })}
            />
            <p className="mt-1 text-right font-mono text-[11px] tabular-nums text-content-tertiary">
              {instructions.length} / {MAX_INSTRUCTIONS.toLocaleString()}
            </p>
          </>
        )}
      </Field>

      <fieldset className="space-y-3">
        <legend className="text-sm font-medium text-content">Tone</legend>

        <Dial
          label="Formal"
          rightLabel="Casual"
          value={personality.formality ?? 0.5}
          onChange={(value) =>
            onChange({ personality: { ...agent.personality, formality: value } })
          }
        />
        <Dial
          label="Brief"
          rightLabel="Detailed"
          value={personality.verbosity ?? 0.35}
          onChange={(value) =>
            onChange({ personality: { ...agent.personality, verbosity: value } })
          }
        />
      </fieldset>

      <Field label="Greeting" hint="The first thing a visitor sees when the panel opens.">
        {({ id }) => (
          <Textarea
            id={id}
            rows={2}
            value={agent.greeting ?? ''}
            onChange={(event) => onChange({ greeting: event.target.value })}
          />
        )}
      </Field>

      <Field
        label="When it cannot answer"
        hint="Shown when the knowledge does not cover the question, and when the month's budget is spent. Never an error message."
      >
        {({ id }) => (
          <Textarea
            id={id}
            rows={3}
            value={agent.fallback_message ?? ''}
            onChange={(event) => onChange({ fallback_message: event.target.value })}
          />
        )}
      </Field>

      <div className="space-y-4 rounded-xl border border-border bg-surface-sunken p-4">
        <p className="text-sm font-medium text-content">Model</p>

        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Provider">
            {({ id }) => (
              <Select
                id={id}
                value={provider}
                onChange={(event) =>
                  onChange({
                    model_config: {
                      ...agent.model_config,
                      provider: event.target.value,
                      model: '',
                    },
                  })
                }
              >
                <option value="">Choose a provider</option>
                {configured.map((option) => (
                  <option key={option.provider} value={option.provider}>
                    {option.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>

          <Field label="Model">
            {({ id }) => (
              <Select
                id={id}
                value={model.model ?? ''}
                disabled={provider === ''}
                onChange={(event) =>
                  onChange({
                    model_config: { ...agent.model_config, model: event.target.value },
                  })
                }
              >
                <option value="">Choose a model</option>
                {(models.data ?? []).map((option) => (
                  <option key={option.id} value={option.id}>
                    {option.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </div>

        {configured.length === 0 && (
          <p className="text-xs leading-relaxed text-content-secondary">
            No provider key is stored yet. Add one under Settings → Providers;
            a clerk cannot go on duty without a model.
          </p>
        )}

        <p className="text-xs leading-relaxed text-content-tertiary">
          {/* The measurement from Sprint 5's M2 gate: our own contribution
              to first-token latency is ~35 ms, so this field is the lever. */}
          Model choice decides how fast the first word appears. A reasoning
          model can think for tens of seconds before it says anything, and no
          transport can stream what the provider has not sent.
        </p>

        <div className="grid gap-3 sm:grid-cols-2">
          <Field
            label="Temperature"
            hint="Low is right for a clerk quoting a policy. Higher invents more."
          >
            {({ id }) => (
              <Input
                id={id}
                type="number"
                min={0}
                max={1}
                step={0.1}
                value={model.temperature ?? 0.3}
                onChange={(event) =>
                  onChange({
                    model_config: {
                      ...agent.model_config,
                      temperature: Number(event.target.value),
                    },
                  })
                }
              />
            )}
          </Field>

          <Field label="Longest reply" hint="In tokens. Roughly 750 words per 1,000.">
            {({ id }) => (
              <Input
                id={id}
                type="number"
                min={64}
                max={4096}
                step={64}
                value={model.max_tokens ?? 800}
                onChange={(event) =>
                  onChange({
                    model_config: {
                      ...agent.model_config,
                      max_tokens: Number(event.target.value),
                    },
                  })
                }
              />
            )}
          </Field>
        </div>
      </div>
    </div>
  );
}

interface DialProps {
  label: string;
  rightLabel: string;
  value: number;
  onChange: (value: number) => void;
}

function Dial({ label, rightLabel, value, onChange }: DialProps) {
  return (
    <div className="flex items-center gap-3">
      <span className="w-16 shrink-0 text-xs text-content-tertiary">{label}</span>
      <input
        type="range"
        min={0}
        max={1}
        step={0.05}
        value={value}
        aria-label={`${label} to ${rightLabel}`}
        onChange={(event) => onChange(Number(event.target.value))}
        className="h-1 flex-1 cursor-pointer appearance-none rounded-full bg-surface-sunken accent-[var(--hvc-accent)]"
      />
      <span className="w-16 shrink-0 text-right text-xs text-content-tertiary">
        {rightLabel}
      </span>
    </div>
  );
}
