import { Field, Select, Textarea } from '@/components/ui/Field';
import { cn } from '@/lib/cn';
import type { AgentDetail, AgentInput, DisplayRules } from '@/api/queries/useAgents';

interface TabProps {
  agent: AgentDetail;
  onChange: (patch: AgentInput) => void;
}

const DEVICES = [
  { key: 'desktop', label: 'Desktop' },
  { key: 'mobile', label: 'Phone' },
  { key: 'tablet', label: 'Tablet' },
] as const;

/**
 * Where the clerk appears (FR-CLK-07).
 *
 * Patterns are edited as lines of text rather than as a chip builder,
 * because the thing an operator actually does here is paste a URL out of
 * their browser and add an asterisk. Every rule states its consequence,
 * and the summary at the bottom says in one sentence what the whole set
 * adds up to — which is the only way to check a rule set without a site
 * to try it on.
 */
export function DisplayTab({ agent, onChange }: TabProps) {
  const rules = agent.display_rules;

  const set = (patch: Partial<DisplayRules>) =>
    onChange({ display_rules: { ...rules, ...patch } });

  const toggleDevice = (device: string) => {
    const next = rules.devices.includes(device)
      ? rules.devices.filter((entry) => entry !== device)
      : [...rules.devices, device];

    set({ devices: next });
  };

  return (
    <div className="space-y-6">
      <Field
        label="Only on these pages"
        hint="One path per line. * matches anything: /products/* covers every product. Leave empty for every page."
      >
        {({ id, describedBy }) => (
          <Textarea
            id={id}
            rows={4}
            className="font-mono text-[13px]"
            value={rules.include.join('\n')}
            aria-describedby={describedBy}
            placeholder="/products/*"
            onChange={(event) => set({ include: toLines(event.target.value) })}
          />
        )}
      </Field>

      <Field
        label="Never on these pages"
        hint="Beats the list above. Checkout and account pages are the usual entries — a chat panel over a payment form costs sales."
      >
        {({ id, describedBy }) => (
          <Textarea
            id={id}
            rows={3}
            className="font-mono text-[13px]"
            value={rules.exclude.join('\n')}
            aria-describedby={describedBy}
            placeholder="/checkout*"
            onChange={(event) => set({ exclude: toLines(event.target.value) })}
          />
        )}
      </Field>

      <fieldset>
        <legend className="text-sm font-medium text-content">Devices</legend>
        <p className="mb-2 mt-1 text-xs text-content-tertiary">
          Ticking none is the same as ticking all.
        </p>

        <div className="flex flex-wrap gap-2">
          {DEVICES.map((device) => {
            const on = rules.devices.includes(device.key);

            return (
              <label
                key={device.key}
                className={cn(
                  'cursor-pointer rounded-lg border px-3 py-1.5 text-sm transition-colors',
                  'focus-within:border-accent',
                  on
                    ? 'border-accent bg-accent-subtle text-content'
                    : 'border-border bg-surface text-content-secondary hover:border-border-strong'
                )}
              >
                <input
                  type="checkbox"
                  className="sr-only"
                  checked={on}
                  onChange={() => toggleDevice(device.key)}
                />
                {device.label}
              </label>
            );
          })}
        </div>
      </fieldset>

      <Field label="Who sees it">
        {({ id }) => (
          <Select
            id={id}
            className="max-w-xs"
            value={rules.audience}
            onChange={(event) =>
              set({ audience: event.target.value as DisplayRules['audience'] })
            }
          >
            <option value="everyone">Everyone</option>
            <option value="logged_in">Signed-in visitors only</option>
            <option value="logged_out">Signed-out visitors only</option>
          </Select>
        )}
      </Field>

      <Field
        label="Roles"
        hint="One per line, as WordPress names them: customer, subscriber. Only narrows signed-in visitors; an anonymous visitor holds no role and is never excluded by this."
      >
        {({ id, describedBy }) => (
          <Textarea
            id={id}
            rows={2}
            className="font-mono text-[13px]"
            value={rules.roles.join('\n')}
            aria-describedby={describedBy}
            placeholder="customer"
            onChange={(event) => set({ roles: toLines(event.target.value) })}
          />
        )}
      </Field>

      <Field
        label="Countries"
        hint="Two-letter codes, one per line. Only works behind a CDN that reports the visitor's country; where nothing reports one, the clerk still appears."
      >
        {({ id, describedBy }) => (
          <Textarea
            id={id}
            rows={2}
            className="font-mono text-[13px]"
            value={rules.countries.join('\n')}
            aria-describedby={describedBy}
            placeholder="DE"
            onChange={(event) => set({ countries: toLines(event.target.value) })}
          />
        )}
      </Field>

      <p className="rounded-xl border border-border bg-surface-sunken px-4 py-3 text-sm leading-relaxed text-content-secondary">
        {summarise(rules)}
      </p>
    </div>
  );
}

function toLines(value: string): string[] {
  return value
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '');
}

/**
 * The whole rule set in one sentence.
 *
 * Every clause is joined with "and", because that is how the engine reads
 * them: a clerk appears only where every test passes.
 */
function summarise(rules: DisplayRules): string {
  const clauses: string[] = [];

  clauses.push(
    rules.include.length === 0
      ? 'on every page'
      : `on ${rules.include.length} matching ${rules.include.length === 1 ? 'path' : 'paths'}`
  );

  if (rules.exclude.length > 0) {
    clauses.push(`except ${rules.exclude.length}`);
  }

  if (rules.devices.length > 0) {
    clauses.push(`on ${rules.devices.join(' and ')}`);
  }

  if (rules.audience === 'logged_in') {
    clauses.push('for signed-in visitors');
  }

  if (rules.audience === 'logged_out') {
    clauses.push('for signed-out visitors');
  }

  if (rules.roles.length > 0) {
    clauses.push(`holding ${rules.roles.join(' or ')}`);
  }

  if (rules.countries.length > 0) {
    clauses.push(`in ${rules.countries.join(', ')}`);
  }

  return `This clerk appears ${clauses.join(', ')}.`;
}
