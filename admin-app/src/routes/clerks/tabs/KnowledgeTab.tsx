import { Link } from 'react-router-dom';
import { Skeleton } from '@/components/ui/Skeleton';
import { formatCompact } from '@/lib/format';
import { cn } from '@/lib/cn';
import { useSources } from '@/api/queries/useKnowledge';
import type { AgentDetail, AgentInput } from '@/api/queries/useAgents';

interface TabProps {
  agent: AgentDetail;
  onChange: (patch: AgentInput) => void;
}

/**
 * What this clerk is allowed to read.
 *
 * Retrieval is scoped to the sources ticked here (FR-CLK-04), which is
 * also the privacy control: a clerk that answers about pricing has no
 * business reading the internal handbook, and the only way to be sure of
 * that is for it never to be given it.
 */
export function KnowledgeTab({ agent, onChange }: TabProps) {
  const { data, isPending } = useSources();

  const selected = new Set(agent.source_uuids);

  const toggle = (uuid: string) => {
    const next = new Set(selected);

    if (next.has(uuid)) {
      next.delete(uuid);
    } else {
      next.add(uuid);
    }

    onChange({ source_uuids: [...next] });
  };

  if (isPending) {
    return (
      <div className="space-y-2">
        {[0, 1, 2].map((i) => (
          <Skeleton key={i} className="h-14 w-full rounded-lg" />
        ))}
      </div>
    );
  }

  const sources = data?.sources ?? [];

  return (
    <div className="space-y-4">
      <p className="text-sm leading-relaxed text-content-secondary">
        {selected.size === 0
          ? 'This clerk answers from its instructions alone. That is a real configuration — a qualifier that asks three questions needs no knowledge — but a support clerk with nothing to read can only decline.'
          : `Retrieval is scoped to ${selected.size} of ${sources.length} ${sources.length === 1 ? 'source' : 'sources'}. Nothing else is searched.`}
      </p>

      {sources.length === 0 ? (
        <p className="rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-content-tertiary">
          Nothing is indexed yet.{' '}
          <Link
            to="/knowledge/sources"
            className="text-accent-text underline-offset-4 hover:underline"
          >
            Add a knowledge source
          </Link>{' '}
          and it will appear here.
        </p>
      ) : (
        <ul className="space-y-2">
          {sources.map((source) => {
            const checked = selected.has(source.uuid);

            return (
              <li key={source.uuid}>
                <label
                  className={cn(
                    'flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5',
                    'transition-colors focus-within:border-accent',
                    checked
                      ? 'border-accent bg-accent-subtle'
                      : 'border-border bg-surface hover:border-border-strong'
                  )}
                >
                  <input
                    type="checkbox"
                    className="size-4 accent-[var(--hvc-accent)]"
                    checked={checked}
                    onChange={() => toggle(source.uuid)}
                  />

                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm text-content">
                      {source.name}
                    </span>
                    <span className="block text-xs text-content-tertiary">
                      {source.type_label} ·{' '}
                      {/* Chunks stored and vectors written are different
                          facts, and only the second one makes a source
                          answerable. Saying "indexed" for both is how an
                          operator ends up debugging a working clerk. */}
                      {source.is_searchable
                        ? `${formatCompact(source.chunk_count)} chunks`
                        : 'not searchable yet — no vectors'}
                    </span>
                  </span>
                </label>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
