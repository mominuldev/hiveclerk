import { useState } from 'react';
import { Flag, Star, UserRound } from 'lucide-react';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Filters } from '@/components/ui/Filters';
import { Pagination } from '@/components/ui/Pagination';
import { Skeleton } from '@/components/ui/Skeleton';
import { formatCost } from '@/lib/format';
import { cn } from '@/lib/cn';
import { useRoster } from '@/stores/useRoster';
import { Transcript } from './Transcript';
import { RetentionNote } from './RetentionNote';
import {
  useConversations,
  type ConversationFilters,
  type ConversationSummary,
} from '@/api/queries/useConversations';

type Scope = 'attention' | 'all' | 'handoff' | 'negative' | 'leads' | 'starred';

const SCOPES: Array<{ key: Scope; label: string }> = [
  { key: 'attention', label: 'Needs reply' },
  { key: 'all', label: 'All' },
  { key: 'handoff', label: 'Handoffs' },
  { key: 'negative', label: 'Negative' },
  { key: 'leads', label: 'Leads' },
  { key: 'starred', label: 'Starred' },
];

function scopeFilters(scope: Scope): ConversationFilters {
  switch (scope) {
    case 'attention':
      return { status: 'handoff_requested' };
    case 'handoff':
      return { handoff: true };
    case 'negative':
      return { rating: -1 };
    case 'leads':
      return { has_lead: true };
    case 'starred':
      return { starred: true };
    default:
      return {};
  }
}

/**
 * Conversations: a list on the left, a transcript on the right.
 *
 * The default scope is "needs reply" rather than "all". This screen is
 * opened for one of two reasons — somebody is waiting, or somebody is
 * auditing an answer — and only the first one is urgent.
 */
export function Conversations() {
  const [scope, setScope] = useState<Scope>('attention');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<string | null>(null);

  // The roster rail is a persistent filter across the whole app, so a
  // clerk selected there narrows this list rather than navigating away.
  const clerk = useRoster((state) => state.selected);

  const filters: ConversationFilters = {
    ...scopeFilters(scope),
    ...(search ? { search } : {}),
    ...(clerk ? { agent: clerk } : {}),
    page,
  };

  const { data, isPending, isError, error, refetch } = useConversations(filters);

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  const conversations = data?.conversations ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Scope">
          {SCOPES.map((item) => {
            const active = item.key === scope;

            return (
              <button
                key={item.key}
                type="button"
                role="tab"
                aria-selected={active}
                onClick={() => {
                  setScope(item.key);
                  setPage(1);
                }}
                className={cn(
                  'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                  active
                    ? 'border-accent bg-accent-subtle text-accent-text'
                    : 'border-border bg-surface text-content-secondary hover:border-border-strong'
                )}
              >
                {item.label}
              </button>
            );
          })}
        </div>

        <Filters
          search={{
            value: search,
            onChange: (value) => {
              setSearch(value);
              setPage(1);
            },
            placeholder: 'Search pages and summaries',
          }}
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
        <div className="space-y-2">
          {isPending ? (
            [0, 1, 2].map((i) => <Skeleton key={i} className="h-20 w-full rounded-xl" />)
          ) : conversations.length === 0 ? (
            <div className="rounded-xl border border-dashed border-border">
              <EmptyState
                bare
                title={scope === 'attention' ? 'Nobody is waiting' : 'Nothing here'}
                description={
                  scope === 'attention'
                    ? 'No visitor has asked for a person. This is the state you want this screen to be in.'
                    : 'No conversations match. Widen the filters, or check the retention policy below — history does not last forever by design.'
                }
              />
            </div>
          ) : (
            conversations.map((conversation) => (
              <ConversationRow
                key={conversation.uuid}
                conversation={conversation}
                selected={conversation.uuid === selected}
                onSelect={() => setSelected(conversation.uuid)}
              />
            ))
          )}

          {data && data.totalPages > 1 && (
            <Pagination
              meta={{
                page,
                per_page: 25,
                total: data.total,
                total_pages: data.totalPages,
              }}
              onChange={setPage}
              noun="conversation"
            />
          )}

          <RetentionNote />
        </div>

        <Transcript uuid={selected} onClose={() => setSelected(null)} />
      </div>
    </div>
  );
}

interface RowProps {
  conversation: ConversationSummary;
  selected: boolean;
  onSelect: () => void;
}

function ConversationRow({ conversation, selected, onSelect }: RowProps) {
  return (
    <button
      type="button"
      onClick={onSelect}
      aria-current={selected ? 'true' : undefined}
      className={cn(
        'w-full rounded-xl border px-3 py-2.5 text-left transition-colors',
        selected
          ? 'border-accent bg-accent-subtle'
          : 'border-border bg-surface hover:border-border-strong'
      )}
    >
      <span className="flex items-center justify-between gap-2">
        <span className="flex min-w-0 items-center gap-1.5">
          {conversation.needs_attention && (
            <Flag size={12} className="shrink-0 text-warning" aria-hidden="true" />
          )}
          {conversation.starred && (
            <Star size={12} className="shrink-0 text-warning" aria-hidden="true" />
          )}
          <span className="truncate text-sm font-medium text-content">
            {conversation.agent.name}
          </span>
        </span>

        <span className="shrink-0 font-mono text-[11px] tabular-nums text-content-tertiary">
          {(conversation.last_message_at ?? conversation.started_at ?? '').slice(5, 16)}
        </span>
      </span>

      <span className="mt-1 block truncate text-xs text-content-secondary">
        {conversation.preview === '' ? 'No summary yet' : conversation.preview}
      </span>

      <span className="mt-1.5 flex flex-wrap items-center gap-2 text-[11px] text-content-tertiary">
        <span>
          {conversation.message_count}{' '}
          {conversation.message_count === 1 ? 'message' : 'messages'}
        </span>
        <span className="font-mono tabular-nums">{formatCost(conversation.cost)}</span>
        {conversation.human_handled && (
          <span className="inline-flex items-center gap-1 text-content-secondary">
            <UserRound size={10} aria-hidden="true" />
            {conversation.handoff_user ?? 'Taken over'}
          </span>
        )}
        {conversation.tags.map((tag) => (
          <span key={tag} className="rounded-full bg-surface-sunken px-1.5 py-px">
            {tag}
          </span>
        ))}
      </span>
    </button>
  );
}
