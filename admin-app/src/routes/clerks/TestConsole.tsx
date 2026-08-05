import { useRef, useState } from 'react';
import { RotateCcw, Send } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import { StatRow } from '@/components/ui/Card';
import { formatCost } from '@/lib/format';
import { cn } from '@/lib/cn';
import {
  useTestAgent,
  type AgentDetail,
  type TestResult,
} from '@/api/queries/useAgents';

interface TestConsoleProps {
  agent: AgentDetail;
  dirty: boolean;
  onSave: () => void;
  saving: boolean;
}

interface Turn {
  role: 'visitor' | 'assistant';
  content: string;
  result?: TestResult;
}

/**
 * The permanent test console (FR-CLK-08).
 *
 * It runs the saved clerk, not the form. That distinction is the one
 * thing this panel has to be honest about: a console that silently tested
 * unsaved edits would be testing a clerk that does not exist, and one
 * that silently tested the saved version while the operator watched their
 * new instructions on screen would be worse. So it says which, and offers
 * to save.
 */
export function TestConsole({ agent, dirty, onSave, saving }: TestConsoleProps) {
  const [turns, setTurns] = useState<Turn[]>([]);
  const [draft, setDraft] = useState('');
  const test = useTestAgent(agent.uuid);
  const transcript = useRef<HTMLDivElement>(null);

  const send = () => {
    const message = draft.trim();

    if (message === '' || test.isPending) {
      return;
    }

    const history = turns.map(({ role, content }) => ({ role, content }));

    setTurns((current) => [...current, { role: 'visitor', content: message }]);
    setDraft('');

    test.mutate(
      { message, history },
      {
        onSuccess: (result) => {
          setTurns((current) => [
            ...current,
            { role: 'assistant', content: result.reply, result },
          ]);

          window.requestAnimationFrame(() => {
            transcript.current?.scrollTo({
              top: transcript.current.scrollHeight,
              behavior: 'smooth',
            });
          });
        },
      }
    );
  };

  const last = [...turns].reverse().find((turn) => turn.result)?.result;

  return (
    <aside className="flex min-h-[32rem] flex-col rounded-xl border border-border bg-surface">
      <header className="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
        <h2 className="text-[10px] font-semibold uppercase tracking-[0.12em] text-content-tertiary">
          Test console
        </h2>
        <Button size="sm" variant="ghost" onClick={() => setTurns([])} disabled={turns.length === 0}>
          <RotateCcw size={13} aria-hidden="true" />
          Reset
        </Button>
      </header>

      {dirty && (
        <div className="border-b border-border bg-surface-sunken px-4 py-2.5">
          <p className="text-xs leading-relaxed text-content-secondary">
            The console runs the saved clerk. Your unsaved changes are not in
            it yet.
          </p>
          <Button size="sm" className="mt-2" loading={saving} onClick={onSave}>
            Save and test
          </Button>
        </div>
      )}

      <div
        ref={transcript}
        className="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-3"
        aria-live="polite"
      >
        {turns.length === 0 && (
          <p className="text-sm leading-relaxed text-content-tertiary">
            Ask {agent.name} something a customer would ask. Every run shows
            what it retrieved, what it cost, and whether the answer was
            grounded in a source.
          </p>
        )}

        {turns.map((turn, index) => (
          <div
            key={`${index}-${turn.role}`}
            className={cn(
              'rounded-xl border px-3 py-2.5 text-sm leading-relaxed',
              turn.role === 'visitor'
                ? 'border-border bg-surface-sunken text-content'
                : 'border-accent/25 bg-accent-subtle text-content'
            )}
          >
            <p className="mb-1 text-[11px] font-medium text-content-tertiary">
              {turn.role === 'visitor' ? 'You' : agent.name}
            </p>

            {/* Model output rendered as text. Never as HTML, anywhere. */}
            <p className="whitespace-pre-wrap">
              {turn.content === '' ? '(no reply)' : turn.content}
            </p>

            {turn.result && turn.result.citations.length > 0 && (
              <ul className="mt-2 space-y-1 border-t border-border/60 pt-2">
                {turn.result.citations.map((citation, position) => (
                  <li
                    key={`${citation.chunk_id ?? position}`}
                    className="flex items-baseline justify-between gap-2 text-xs"
                  >
                    <span className="min-w-0 truncate text-content-secondary">
                      ▸ {citation.title}
                      {citation.heading_path ? ` · ${citation.heading_path}` : ''}
                    </span>
                    <span
                      className={cn(
                        'shrink-0 font-mono tabular-nums',
                        citation.confident ? 'text-content-secondary' : 'text-warning'
                      )}
                      title={
                        citation.confident
                          ? 'Above this clerk’s confidence threshold'
                          : 'Below the threshold — retrieved but not trusted'
                      }
                    >
                      {citation.score.toFixed(2)}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        ))}

        {test.isPending && (
          <p className="text-xs text-content-tertiary">Asking {agent.name}…</p>
        )}

        {test.isError && (
          <p className="text-xs text-danger" role="alert">
            {test.error.message}
          </p>
        )}
      </div>

      {last && <Diagnostics result={last} />}

      <div className="flex items-center gap-2 border-t border-border px-3 py-3">
        <Input
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
              event.preventDefault();
              send();
            }
          }}
          placeholder={`Ask ${agent.name} something…`}
          aria-label={`Ask ${agent.name} something`}
        />
        <Button
          variant="primary"
          aria-label="Send"
          loading={test.isPending}
          onClick={send}
        >
          <Send size={14} aria-hidden="true" />
        </Button>
      </div>
    </aside>
  );
}

function Diagnostics({ result }: { result: TestResult }) {
  const d = result.diagnostics;
  const flags = d.guardrails_triggered ?? [];

  return (
    <div className="border-t border-border px-4 py-3">
      <p className="mb-2 text-[10px] font-semibold uppercase tracking-[0.12em] text-content-tertiary">
        Diagnostics
      </p>

      <dl>
        <StatRow label="Retrieval" value={`${d.retrieval_ms} ms`} />
        <StatRow label="Completion" value={`${d.completion_ms} ms`} />
        <StatRow
          label="Tokens"
          value={
            d.tokens_in === undefined
              ? '—'
              : `${d.tokens_in.toLocaleString()} → ${(d.tokens_out ?? 0).toLocaleString()}`
          }
        />
        <StatRow
          label="Cost"
          value={
            // Null is "we hold no price for this model", which is not the
            // same claim as "this was free".
            d.cost === null || d.cost === undefined ? 'Unpriced' : formatCost(d.cost)
          }
          emphasis
        />
        <StatRow label="Grounded" value={d.grounded ? 'Yes' : 'No'} />
        <StatRow
          label="Guardrails"
          value={flags.length === 0 ? 'None' : flags.join(', ')}
        />
        {d.dropped_chunks !== undefined && d.dropped_chunks > 0 && (
          <StatRow
            label="Chunks cut for budget"
            value={String(d.dropped_chunks)}
          />
        )}
      </dl>

      {d.refused_because && (
        <p className="mt-2 text-xs leading-relaxed text-content-secondary">
          {d.refused_because}
        </p>
      )}

      {result.error && (
        <p className="mt-2 text-xs leading-relaxed text-danger" role="alert">
          {result.error}
        </p>
      )}

      {d.prompt_preview && (
        <details className="mt-2">
          <summary className="cursor-pointer text-xs text-accent-text">
            View full prompt
          </summary>
          <pre className="mt-2 max-h-64 overflow-auto rounded-lg bg-surface-sunken p-3 font-mono text-[11px] leading-relaxed text-content-secondary">
            {d.prompt_preview}
          </pre>
        </details>
      )}
    </div>
  );
}
