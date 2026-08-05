import { useState } from 'react';
import { Check, Send, Star, StickyNote, UserRound, X } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Input, Textarea } from '@/components/ui/Field';
import { Skeleton } from '@/components/ui/Skeleton';
import { toast } from '@/components/ui/Toast';
import { formatCost, formatTimestamp } from '@/lib/format';
import { cn } from '@/lib/cn';
import {
  useAddNote,
  useConversation,
  useHumanReply,
  useResolveConversation,
  useTagConversation,
  useTakeover,
  type TranscriptMessage,
} from '@/api/queries/useConversations';

interface TranscriptProps {
  uuid: string | null;
  onClose: () => void;
}

/**
 * One conversation, in full (FR-CNV-02, 03, 04).
 *
 * Citations sit under the message that used them and cost sits on every
 * assistant turn, because the question this pane exists to answer is not
 * "what did it say" but "why did it say that, and what did it cost".
 */
export function Transcript({ uuid, onClose }: TranscriptProps) {
  const { data, isPending, isError, error, refetch } = useConversation(uuid);

  const takeover = useTakeover(uuid ?? '');
  const reply = useHumanReply(uuid ?? '');
  const resolve = useResolveConversation(uuid ?? '');
  const tag = useTagConversation(uuid ?? '');
  const note = useAddNote(uuid ?? '');

  const [draft, setDraft] = useState('');
  const [noteDraft, setNoteDraft] = useState('');
  const [tagDraft, setTagDraft] = useState('');
  const [noting, setNoting] = useState(false);

  if (uuid === null) {
    return (
      <div className="rounded-xl border border-dashed border-border">
        <EmptyState
          bare
          title="Pick a conversation"
          description="Every answer shows the sources it used, what it cost and how long it took."
        />
      </div>
    );
  }

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  if (isPending || !data) {
    return <Skeleton className="h-96 w-full rounded-xl" />;
  }

  const send = () => {
    const message = draft.trim();

    if (message === '') {
      return;
    }

    reply.mutate(
      { message },
      {
        onSuccess: () => {
          setDraft('');
          toast.success('Sent. The clerk has stopped replying in this conversation.');
        },
        onError: (failure) => toast.error('Not sent', failure.message),
      }
    );
  };

  return (
    <section className="flex min-h-[32rem] flex-col rounded-xl border border-border bg-surface">
      <header className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-4 py-3">
        <div className="min-w-0">
          <h2 className="font-display text-base font-bold tracking-[-0.01em] text-content">
            Anonymous visitor
          </h2>
          <p className="mt-0.5 truncate text-xs text-content-tertiary">
            {data.agent.name}
            {data.page_url ? ` · ${data.page_url}` : ''}
            {data.started_at ? ` · ${formatTimestamp(data.started_at)}` : ''}
          </p>

          <div className="mt-2 flex flex-wrap items-center gap-1.5">
            {data.needs_attention && <Badge tone="warning">Handoff requested</Badge>}
            {data.human_handled && (
              <Badge tone="info">
                <UserRound size={10} aria-hidden="true" />
                {data.handoff_user ?? 'Taken over'}
              </Badge>
            )}
            {data.resolved_by_ai && <Badge tone="positive">Resolved by clerk</Badge>}
            {data.tags.map((entry) => (
              <Badge key={entry}>
                {entry}
                <button
                  type="button"
                  aria-label={`Remove tag ${entry}`}
                  className="-mr-1 ml-0.5 rounded p-0.5 hover:text-content"
                  onClick={() =>
                    tag.mutate({ tags: data.tags.filter((item) => item !== entry) })
                  }
                >
                  <X size={10} aria-hidden="true" />
                </button>
              </Badge>
            ))}
          </div>
        </div>

        <div className="flex shrink-0 items-center gap-1.5">
          <Button
            size="sm"
            variant="ghost"
            aria-label={data.starred ? 'Unstar' : 'Star'}
            aria-pressed={data.starred}
            onClick={() => tag.mutate({ starred: !data.starred })}
          >
            <Star
              size={14}
              aria-hidden="true"
              className={data.starred ? 'text-warning' : undefined}
            />
          </Button>

          <Button size="sm" variant="ghost" onClick={() => setNoting((open) => !open)}>
            <StickyNote size={13} aria-hidden="true" />
            Notes
            {data.notes.length > 0 ? ` (${data.notes.length})` : ''}
          </Button>

          {!data.human_handled && (
            <Button
              size="sm"
              loading={takeover.isPending}
              onClick={() =>
                takeover.mutate(undefined, {
                  onSuccess: () =>
                    toast.success(`${data.agent.name} has stopped replying here.`),
                })
              }
            >
              Take over
            </Button>
          )}

          <Button
            size="sm"
            loading={resolve.isPending}
            onClick={() => resolve.mutate(undefined)}
          >
            <Check size={13} aria-hidden="true" />
            Resolve
          </Button>

          <Button size="sm" variant="ghost" aria-label="Close transcript" onClick={onClose}>
            <X size={14} aria-hidden="true" />
          </Button>
        </div>
      </header>

      {noting && (
        <div className="space-y-2 border-b border-border bg-surface-sunken px-4 py-3">
          {data.notes.length === 0 ? (
            <p className="text-xs text-content-tertiary">
              No notes. Visitors never see these.
            </p>
          ) : (
            <ul className="space-y-2">
              {data.notes.map((entry, index) => (
                <li key={`${entry.created_at}-${index}`} className="text-xs">
                  <span className="whitespace-pre-wrap text-content">{entry.text}</span>
                  <span className="mt-0.5 block text-content-tertiary">
                    {entry.author_name} · {formatTimestamp(entry.created_at)}
                  </span>
                </li>
              ))}
            </ul>
          )}

          <div className="flex items-start gap-2">
            <Textarea
              rows={2}
              value={noteDraft}
              aria-label="Add an internal note"
              placeholder="Internal note — the visitor never sees this."
              onChange={(event) => setNoteDraft(event.target.value)}
            />
            <Button
              size="sm"
              loading={note.isPending}
              onClick={() => {
                const text = noteDraft.trim();

                if (text === '') {
                  return;
                }

                note.mutate({ note: text }, { onSuccess: () => setNoteDraft('') });
              }}
            >
              Add
            </Button>
          </div>

          <div className="flex items-center gap-2">
            <Input
              value={tagDraft}
              aria-label="Add a tag"
              placeholder="Add a tag"
              className="max-w-48"
              onChange={(event) => setTagDraft(event.target.value)}
              onKeyDown={(event) => {
                if (event.key !== 'Enter') {
                  return;
                }

                event.preventDefault();
                const value = tagDraft.trim();

                if (value !== '' && !data.tags.includes(value)) {
                  tag.mutate({ tags: [...data.tags, value] });
                }

                setTagDraft('');
              }}
            />
          </div>
        </div>
      )}

      <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4">
        {data.messages.map((message) => (
          <Turn key={message.uuid} message={message} clerk={data.agent.name} />
        ))}
      </div>

      <div className="border-t border-border px-4 py-3">
        <div className="flex items-start gap-2">
          <Textarea
            rows={2}
            value={draft}
            aria-label="Reply as yourself"
            placeholder="Reply as yourself…"
            onChange={(event) => setDraft(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                send();
              }
            }}
          />
          <Button variant="primary" aria-label="Send reply" loading={reply.isPending} onClick={send}>
            <Send size={14} aria-hidden="true" />
          </Button>
        </div>

        <p className="mt-1.5 text-[11px] text-content-tertiary">
          {data.agent.name} stops replying once you take over. The visitor sees
          your reply when their chat next loads the transcript.
        </p>
      </div>
    </section>
  );
}

function Turn({ message, clerk }: { message: TranscriptMessage; clerk: string }) {
  const who =
    message.role === 'visitor'
      ? 'Visitor'
      : message.role === 'human_agent'
        ? (message.author ?? 'A colleague')
        : clerk;

  return (
    <article>
      <header className="mb-1 flex items-baseline justify-between gap-3">
        <span
          className={cn(
            'text-xs font-medium',
            message.role === 'visitor' ? 'text-content-secondary' : 'text-content'
          )}
        >
          {who}
        </span>
        <span className="font-mono text-[11px] tabular-nums text-content-tertiary">
          {(message.created_at ?? '').slice(11, 16)}
        </span>
      </header>

      {/* Rendered as text. This is model output and visitor input, and the
          one thing neither may become is markup in our own screen. */}
      <p className="whitespace-pre-wrap text-sm leading-relaxed text-content">
        {message.content}
      </p>

      {message.citations.length > 0 && (
        <ul className="mt-2 space-y-1">
          {message.citations.map((citation, index) => (
            <li
              key={`${citation.chunk_id ?? index}`}
              className="flex items-baseline justify-between gap-2 text-xs text-content-secondary"
            >
              <span className="min-w-0 truncate">
                ▸ {citation.title}
                {citation.heading_path ? ` · ${citation.heading_path}` : ''}
              </span>
              <span className="shrink-0 font-mono tabular-nums">
                {citation.score.toFixed(2)}
              </span>
            </li>
          ))}
        </ul>
      )}

      {message.flags.length > 0 && (
        <p className="mt-1.5 text-[11px] text-warning">
          Guardrails: {message.flags.join(', ')}
        </p>
      )}

      {message.role === 'assistant' && (
        <p className="mt-1.5 font-mono text-[11px] tabular-nums text-content-tertiary">
          {message.latency_ms !== null ? `⧗ ${(message.latency_ms / 1000).toFixed(1)}s · ` : ''}
          {message.tokens_in.toLocaleString()} → {message.tokens_out.toLocaleString()} tokens
          {' · '}
          {formatCost(message.cost)}
          {message.grounded ? ' · grounded' : ''}
        </p>
      )}
    </article>
  );
}
