import { useState } from 'react';
import { Plus, X } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Field, Input, Textarea } from '@/components/ui/Field';
import { formatCompact, formatCost } from '@/lib/format';
import type { AgentDetail, AgentInput } from '@/api/queries/useAgents';

interface TabProps {
  agent: AgentDetail;
  onChange: (patch: AgentInput) => void;
}

/**
 * The limits (FR-CLK-06, FR-CLK-03).
 *
 * Every control states its consequence underneath it in plain language.
 * This is the screen an operator has to be able to read and know their
 * clerk will not invent a price.
 */
export function GuardrailsTab({ agent, onChange }: TabProps) {
  const [topic, setTopic] = useState('');

  const guardrails = agent.guardrails as {
    no_invent_facts?: boolean;
    confidence_threshold?: number;
    banned_topics?: string[];
    disclaimer?: string;
    on_budget_exhausted?: 'fallback' | 'continue';
    top_k?: number;
  };

  const set = (patch: Record<string, unknown>) =>
    onChange({ guardrails: { ...agent.guardrails, ...patch } });

  const banned = guardrails.banned_topics ?? [];
  const threshold = guardrails.confidence_threshold ?? 0.62;
  const budget = agent.budget;

  return (
    <div className="space-y-7">
      <section>
        <label className="flex items-start gap-3">
          <input
            type="checkbox"
            className="mt-0.5 size-4 accent-[var(--hvc-accent)]"
            checked={guardrails.no_invent_facts !== false}
            onChange={(event) => set({ no_invent_facts: event.target.checked })}
          />
          <span>
            <span className="block text-sm font-medium text-content">
              Never invent facts
            </span>
            <span className="mt-1 block text-xs leading-relaxed text-content-secondary">
              If the answer is not in the knowledge base, the clerk says so and
              offers a person. Off, it will answer from what the model already
              believes about your business — which is usually nothing.
            </span>
          </span>
        </label>
      </section>

      <section>
        <div className="flex items-baseline justify-between gap-3">
          <span className="text-sm font-medium text-content">
            Confidence threshold
          </span>
          <span className="font-mono text-[13px] tabular-nums text-content">
            {threshold.toFixed(2)}
          </span>
        </div>

        <input
          type="range"
          min={0.3}
          max={0.9}
          step={0.01}
          value={threshold}
          aria-label="Confidence threshold"
          onChange={(event) =>
            set({ confidence_threshold: Number(event.target.value) })
          }
          className="mt-2 h-1 w-full cursor-pointer appearance-none rounded-full bg-surface-sunken accent-[var(--hvc-accent)]"
        />

        <p className="mt-2 text-xs leading-relaxed text-content-secondary">
          Below this score the clerk hands off instead of answering. Lower means
          it answers more often, on weaker sources. The retrieval playground
          shows real scores for real questions if you want to pick this from
          evidence rather than feel.
        </p>
      </section>

      <section>
        <span className="text-sm font-medium text-content">Never discuss</span>

        <div className="mt-2 flex flex-wrap items-center gap-1.5">
          {banned.map((entry) => (
            <Badge key={entry}>
              {entry}
              <button
                type="button"
                aria-label={`Remove ${entry}`}
                onClick={() =>
                  set({ banned_topics: banned.filter((item) => item !== entry) })
                }
                className="-mr-1 ml-0.5 rounded p-0.5 hover:text-content"
              >
                <X size={11} aria-hidden="true" />
              </button>
            </Badge>
          ))}

          {banned.length === 0 && (
            <span className="text-xs text-content-tertiary">Nothing yet.</span>
          )}
        </div>

        <div className="mt-2 flex items-center gap-2">
          <Input
            value={topic}
            placeholder="competitor pricing"
            aria-label="Topic to refuse"
            className="max-w-xs"
            onChange={(event) => setTopic(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault();
                const value = topic.trim();

                if (value !== '' && !banned.includes(value)) {
                  set({ banned_topics: [...banned, value] });
                }

                setTopic('');
              }
            }}
          />
          <Button
            size="sm"
            onClick={() => {
              const value = topic.trim();

              if (value !== '' && !banned.includes(value)) {
                set({ banned_topics: [...banned, value] });
              }

              setTopic('');
            }}
          >
            <Plus size={13} aria-hidden="true" />
            Add topic
          </Button>
        </div>

        <p className="mt-2 text-xs leading-relaxed text-content-secondary">
          {/* Stated rather than hidden: it is a keyword match, and a
              paraphrase gets through. An operator who thinks this is a
              classifier will trust it further than it deserves. */}
          Matched as whole words in the visitor&rsquo;s message. It will not
          catch a paraphrase — treat it as a tripwire, not a filter.
        </p>
      </section>

      <section>
        <Field
          label="Always append"
          hint="Added to the end of every reply, word for word. Written by us rather than asked of the model, so it is there every time."
        >
          {({ id, describedBy }) => (
            <Textarea
              id={id}
              rows={2}
              value={guardrails.disclaimer ?? ''}
              aria-describedby={describedBy}
              placeholder="Prices shown exclude VAT."
              onChange={(event) => set({ disclaimer: event.target.value })}
            />
          )}
        </Field>
      </section>

      <section className="space-y-3 rounded-xl border border-border bg-surface-sunken p-4">
        <Field
          label="Monthly token budget"
          hint="Leave empty for no cap. The counter resets on the first of the month."
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              type="number"
              min={0}
              step={10000}
              className="max-w-xs"
              value={agent.token_budget ?? ''}
              aria-describedby={describedBy}
              onChange={(event) =>
                onChange({ token_budget: Number(event.target.value) })
              }
            />
          )}
        </Field>

        <p className="font-mono text-[11px] tabular-nums text-content-tertiary">
          {formatCompact(budget.used)} used this month · resets{' '}
          {budget.resets_at.slice(0, 10)}
          {budget.estimated_cost !== null
            ? ` · ≈ ${formatCost(budget.estimated_cost)} at published rates`
            : ' · no published price for this model'}
        </p>

        <fieldset>
          <legend className="text-sm font-medium text-content">
            When it is exhausted
          </legend>

          <div className="mt-1.5 space-y-1.5">
            {(
              [
                ['fallback', 'Show the fallback message', 'The clerk stops spending. Visitors still get an answer and can still leave an email.'],
                ['continue', 'Keep answering', 'The cap becomes a warning line rather than a limit. Spend continues.'],
              ] as const
            ).map(([value, label, note]) => (
              <label key={value} className="flex items-start gap-2.5">
                <input
                  type="radio"
                  name="on_budget_exhausted"
                  className="mt-0.5 accent-[var(--hvc-accent)]"
                  checked={(guardrails.on_budget_exhausted ?? 'fallback') === value}
                  onChange={() => set({ on_budget_exhausted: value })}
                />
                <span>
                  <span className="block text-sm text-content">{label}</span>
                  <span className="block text-xs leading-relaxed text-content-tertiary">
                    {note}
                  </span>
                </span>
              </label>
            ))}
          </div>
        </fieldset>
      </section>

      <section>
        <Field
          label="Passages per answer"
          hint="How many retrieved passages reach the model. More context costs more on every message of the conversation."
        >
          {({ id, describedBy }) => (
            <Input
              id={id}
              type="number"
              min={1}
              max={12}
              className="max-w-24"
              value={guardrails.top_k ?? 5}
              aria-describedby={describedBy}
              onChange={(event) => set({ top_k: Number(event.target.value) })}
            />
          )}
        </Field>
      </section>
    </div>
  );
}
