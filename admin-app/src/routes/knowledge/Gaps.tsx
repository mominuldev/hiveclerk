import { useState } from 'react';
import { Check, Loader2 } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Skeleton } from '@/components/ui/Skeleton';
import { Pagination } from '@/components/ui/Pagination';
import { toast } from '@/components/ui/Toast';
import {
  useAnswerGap,
  useGaps,
  useSetGapStatus,
  type GapStatus,
  type KnowledgeGap,
} from '@/api/queries/useGaps';
import { cn } from '@/lib/cn';

const FILTERS: Array<{ key: GapStatus | 'all'; label: string }> = [
  { key: 'open', label: 'Open' },
  { key: 'resolved', label: 'Answered' },
  { key: 'ignored', label: 'Ignored' },
  { key: 'all', label: 'All' },
];

/**
 * The knowledge-gaps worklist (D11 §7.3, FR-ANL-03).
 *
 * The most actionable screen in the product, and the whole design follows
 * from that: writing the answer never means leaving it. "Write an answer"
 * opens a composer in place, saves the pair into an FAQ source, queues
 * the re-index and closes the gap in one action.
 *
 * Sorted by how often each question was asked, because that is the order
 * in which answering them pays.
 */
export function Gaps() {
  const [status, setStatus] = useState<GapStatus | 'all'>('open');
  const [page, setPage] = useState(1);
  const [composing, setComposing] = useState<number | null>(null);

  const { data, isPending, isError, error, refetch } = useGaps(status, page);

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-[60ch] text-sm text-content-secondary">
          Questions your clerks couldn&rsquo;t answer from your knowledge. Each
          answer you write closes it for everybody who asks next.
        </p>

        <div className="flex items-center gap-1" role="group" aria-label="Filter by status">
          {FILTERS.map((filter) => (
            <button
              key={filter.key}
              type="button"
              aria-pressed={status === filter.key}
              onClick={() => {
                setStatus(filter.key);
                setPage(1);
              }}
              className={cn(
                'rounded-lg px-2.5 py-1.5 text-sm transition-colors',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                status === filter.key
                  ? 'bg-surface-sunken font-medium text-content'
                  : 'text-content-tertiary hover:text-content-secondary'
              )}
            >
              {filter.label}
              {'all' !== filter.key && data && (
                <span className="ml-1.5 font-mono text-[11px] tabular-nums text-content-tertiary">
                  {data.counts[filter.key]}
                </span>
              )}
            </button>
          ))}
        </div>
      </div>

      {isPending ? (
        <Skeleton className="h-[280px] w-full rounded-xl" />
      ) : 0 === data.gaps.length ? (
        <Card>
          <EmptyState
            title={
              'open' === status
                ? 'Your clerks are answering everything they are asked.'
                : 'Nothing here yet.'
            }
            {...('open' === status
              ? {
                  description:
                    'When a question finds nothing confident in your knowledge, it lands here with a count of how often it has been asked.',
                }
              : {})}
          />
        </Card>
      ) : (
        <Card className="p-0">
          <ul className="divide-y divide-border">
            {data.gaps.map((gap) => (
              <GapRow
                key={gap.id}
                gap={gap}
                composing={composing === gap.id}
                onCompose={(open) => setComposing(open ? gap.id : null)}
              />
            ))}
          </ul>
        </Card>
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
          noun="question"
        />
      )}
    </div>
  );
}

interface GapRowProps {
  gap: KnowledgeGap;
  composing: boolean;
  onCompose: (open: boolean) => void;
}

function GapRow({ gap, composing, onCompose }: GapRowProps) {
  const [answer, setAnswer] = useState('');
  const write = useAnswerGap();
  const setStatus = useSetGapStatus();

  const submit = () => {
    if ('' === answer.trim()) {
      return;
    }

    write.mutate(
      { id: gap.id, answer },
      {
        onSuccess: (result) => {
          onCompose(false);
          setAnswer('');
          // Says what happens next rather than claiming it is live. The
          // pair is saved; it is not searchable until the indexer runs.
          toast.success(
            `Saved to "${result.source.name}".`,
            'Indexing runs in the background. The answer is searchable once it finishes.'
          );
        },
        onError: (mutationError) => {
          toast.error(mutationError.message);
        },
      }
    );
  };

  return (
    <li className="p-4">
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0">
          <p className="text-sm font-medium text-content">
            &ldquo;{gap.question}&rdquo;
          </p>
          <p className="mt-1 text-xs leading-relaxed text-content-tertiary">
            {gap.found_nothing
              ? 'No matching content found.'
              : `Best match scored ${gap.best_score?.toFixed(2)}${
                  gap.agent
                    ? ` — below ${gap.agent.name}'s ${gap.agent.threshold.toFixed(2)} threshold.`
                    : '.'
                }`}
          </p>
        </div>

        <span className="shrink-0 font-mono text-xs tabular-nums text-content-tertiary">
          asked {gap.occurrences}×
        </span>
      </div>

      {'open' === gap.status && !composing && (
        <div className="mt-3 flex items-center gap-2">
          <Button size="sm" variant="primary" onClick={() => onCompose(true)}>
            Write an answer
          </Button>
          <Button
            size="sm"
            variant="ghost"
            loading={setStatus.isPending}
            onClick={() => setStatus.mutate({ id: gap.id, status: 'ignored' })}
          >
            Ignore
          </Button>
        </div>
      )}

      {'open' !== gap.status && (
        <div className="mt-3 flex items-center gap-2">
          <span className="inline-flex items-center gap-1 text-xs text-content-tertiary">
            {'resolved' === gap.status && (
              <Check size={13} aria-hidden="true" className="text-[var(--hvc-on-duty)]" />
            )}
            {gap.status_label}
          </span>
          <Button
            size="sm"
            variant="ghost"
            loading={setStatus.isPending}
            onClick={() => setStatus.mutate({ id: gap.id, status: 'open' })}
          >
            Put it back
          </Button>
        </div>
      )}

      {composing && (
        <div className="mt-3">
          <label
            htmlFor={`hvc-answer-${gap.id}`}
            className="mb-1.5 block text-xs font-medium text-content-secondary"
          >
            Your answer
          </label>
          <textarea
            id={`hvc-answer-${gap.id}`}
            rows={4}
            autoFocus
            value={answer}
            onChange={(event) => setAnswer(event.target.value)}
            placeholder="Answer it the way you would in an email. Give the specific figure — the number of days, the price, the size."
            className={cn(
              'w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-content',
              'placeholder:text-content-tertiary',
              'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent'
            )}
          />

          <div className="mt-2 flex items-center gap-2">
            <Button
              size="sm"
              variant="primary"
              loading={write.isPending}
              disabled={'' === answer.trim()}
              onClick={submit}
              icon={
                write.isPending ? (
                  <Loader2 size={13} aria-hidden="true" className="animate-spin motion-reduce:animate-none" />
                ) : undefined
              }
            >
              Save and index
            </Button>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => {
                onCompose(false);
                setAnswer('');
              }}
            >
              Cancel
            </Button>
          </div>

          <p className="mt-2 text-xs text-content-tertiary">
            This is saved as an FAQ pair against the question above, attached to
            the clerk that could not answer it.
          </p>
        </div>
      )}
    </li>
  );
}
