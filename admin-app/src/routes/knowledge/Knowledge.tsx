import { useState } from 'react';
import {
  AlertCircle,
  FileText,
  Library,
  Plus,
  RefreshCw,
  Trash2,
  X,
} from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Modal } from '@/components/ui/Modal';
import { Skeleton } from '@/components/ui/Skeleton';
import { toast } from '@/components/ui/Toast';
import { formatCompact, formatCost } from '@/lib/format';
import { AddSourceModal } from './AddSourceModal';
import { SourceProgress } from './SourceProgress';
import { SourceInspector } from './SourceInspector';
import {
  useCancelSource,
  useDeleteSource,
  useReindexSource,
  useSources,
  type KnowledgeSource,
} from '@/api/queries/useKnowledge';

/**
 * What the clerks know, and whether it is up to date.
 *
 * The screen is a list rather than a dashboard because the questions it
 * answers are per-source: is this indexed, when did it last run, why did
 * it fail. Aggregate totals across sources of different types would be a
 * number nobody acts on.
 */
export function Knowledge() {
  const { data, isPending, isError, error, refetch } = useSources();
  const [adding, setAdding] = useState(false);
  const [inspecting, setInspecting] = useState<KnowledgeSource | null>(null);
  const [deleting, setDeleting] = useState<KnowledgeSource | null>(null);

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  return (
    <div className="space-y-4">
      {/* No heading here. The shell's top bar already names the screen,
          and repeating it puts "Knowledge" on the page twice. */}
      <div className="flex items-center justify-between gap-4">
        <p className="text-sm text-content-secondary">
          {isPending || data.sources.length === 0
            ? 'Nothing indexed yet.'
            : `${data.total.toLocaleString()} ${data.total === 1 ? 'source' : 'sources'} indexed.`}
        </p>

        <Button variant="primary" onClick={() => setAdding(true)}>
          <Plus size={15} aria-hidden="true" />
          Add source
        </Button>
      </div>

      {isPending ? (
        <div className="space-y-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-24 w-full rounded-xl" />
          ))}
        </div>
      ) : data.sources.length === 0 ? (
        <EmptyState
          title="No knowledge sources yet"
          description="A clerk with nothing to read can only decline to answer. Start with your site's own pages — it takes one click and no configuration."
          action={
            <Button variant="primary" onClick={() => setAdding(true)}>
              <Plus size={15} aria-hidden="true" />
              Add your first source
            </Button>
          }
        />
      ) : (
        <div className="space-y-3">
          {data.sources.map((source) => (
            <SourceRow
              key={source.uuid}
              source={source}
              onInspect={() => setInspecting(source)}
              onDelete={() => setDeleting(source)}
            />
          ))}
        </div>
      )}

      <AddSourceModal open={adding} onClose={() => setAdding(false)} />

      <SourceInspector
        source={inspecting}
        onClose={() => setInspecting(null)}
      />

      <DeleteDialog source={deleting} onClose={() => setDeleting(null)} />
    </div>
  );
}

interface SourceRowProps {
  source: KnowledgeSource;
  onInspect: () => void;
  onDelete: () => void;
}

function SourceRow({ source, onInspect, onDelete }: SourceRowProps) {
  const reindex = useReindexSource();
  const cancel = useCancelSource();

  return (
    <Card className="!p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1 space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="truncate text-sm font-semibold text-content">
              {source.name}
            </h2>
            {/* The type badge is dropped when the operator kept the
                default name, because it is then the same word twice. */}
            {source.name !== source.type_label && (
              <Badge tone="neutral">{source.type_label}</Badge>
            )}
            <StatusBadge source={source} />
          </div>

          {source.is_busy ? (
            <SourceProgress progress={source.progress} />
          ) : (
            <dl className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-content-secondary">
              <Stat label="Documents" value={source.document_count} />
              <Stat label="Chunks" value={source.chunk_count} />
              <Stat label="Tokens" value={source.token_count} compact />
              {source.chunk_count > 0 && (
                <div className="flex gap-1">
                  <dt className="text-content-tertiary">Vectors</dt>
                  <dd className="tabular-nums text-content">
                    {source.vector_count.toLocaleString()}
                    {source.embedding && (
                      <span className="text-content-tertiary">
                        {' '}
                        · {source.embedding.model}
                      </span>
                    )}
                  </dd>
                </div>
              )}
              {source.index_cost !== null && source.index_cost > 0 && (
                <div className="flex gap-1">
                  <dt className="text-content-tertiary">Indexing cost</dt>
                  {/* What it actually cost, from the pinned model's
                      published price and the tokens really stored — not an
                      estimate of what it might cost. */}
                  <dd className="tabular-nums">
                    {formatCost(source.index_cost)}
                  </dd>
                </div>
              )}
              {source.last_synced_at && (
                <div className="flex gap-1">
                  <dt className="text-content-tertiary">Last indexed</dt>
                  <dd className="tabular-nums">
                    {new Date(
                      `${source.last_synced_at.replace(' ', 'T')}Z`
                    ).toLocaleString()}
                  </dd>
                </div>
              )}
            </dl>
          )}

          {source.last_error && !source.is_busy && (
            <p className="flex items-start gap-1.5 text-xs leading-relaxed text-danger">
              <AlertCircle size={13} className="mt-px shrink-0" aria-hidden="true" />
              {source.last_error}
            </p>
          )}
        </div>

        <div className="flex shrink-0 items-center gap-1.5">
          {source.is_busy ? (
            <Button
              variant="secondary"
              size="sm"
              onClick={() =>
                cancel.mutate(source.uuid, {
                  onSuccess: () =>
                    toast.info(
                      'Stopping',
                      'The import finishes the item it is on, then stops.'
                    ),
                })
              }
              loading={cancel.isPending}
            >
              <X size={14} aria-hidden="true" />
              Stop
            </Button>
          ) : (
            <Button
              variant="secondary"
              size="sm"
              onClick={() =>
                reindex.mutate(source.uuid, {
                  onSuccess: () => toast.success('Queued for re-indexing'),
                  onError: (error) => toast.error('Could not queue it', error.message),
                })
              }
              loading={reindex.isPending}
            >
              <RefreshCw size={14} aria-hidden="true" />
              Re-index
            </Button>
          )}

          <Button
            variant="ghost"
            size="sm"
            onClick={onInspect}
            disabled={source.document_count === 0}
          >
            <FileText size={14} aria-hidden="true" />
            Inspect
          </Button>

          <Button
            variant="ghost"
            size="sm"
            onClick={onDelete}
            aria-label={`Delete ${source.name}`}
          >
            <Trash2 size={14} aria-hidden="true" />
          </Button>
        </div>
      </div>
    </Card>
  );
}

function StatusBadge({ source }: { source: KnowledgeSource }) {
  if (source.is_busy) {
    return <Badge tone="info">Indexing</Badge>;
  }

  if (source.status === 'error') {
    return <Badge tone="danger">Needs attention</Badge>;
  }

  if (source.status === 'needs_reembedding') {
    return <Badge tone="warning">Re-embedding needed</Badge>;
  }

  if (source.chunk_count === 0) {
    // Ready with nothing in it is not ready. Saying so here saves the
    // customer discovering it when the clerk cannot answer.
    return <Badge tone="warning">Empty</Badge>;
  }

  if (!source.is_searchable) {
    // Text stored, vectors missing. The most confusing state in the
    // product if left unnamed: the source reports documents and chunks
    // and a clerk still cannot find any of it.
    return <Badge tone="warning">Not searchable</Badge>;
  }

  return <Badge tone="positive">Ready</Badge>;
}

function Stat({
  label,
  value,
  compact = false,
}: {
  label: string;
  value: number;
  compact?: boolean;
}) {
  return (
    <div className="flex gap-1">
      <dt className="text-content-tertiary">{label}</dt>
      <dd className="tabular-nums text-content">
        {compact ? formatCompact(value) : value.toLocaleString()}
      </dd>
    </div>
  );
}

function DeleteDialog({
  source,
  onClose,
}: {
  source: KnowledgeSource | null;
  onClose: () => void;
}) {
  const remove = useDeleteSource();

  return (
    <Modal
      open={source !== null}
      onClose={onClose}
      title="Delete this source?"
      description={
        source
          ? `${source.name} and its ${source.chunk_count.toLocaleString()} indexed chunks will be removed. Clerks using it will stop being able to answer from it.`
          : ''
      }
      danger
      size="sm"
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Keep it
          </Button>
          <Button
            variant="danger"
            loading={remove.isPending}
            onClick={() => {
              if (!source) return;

              remove.mutate(source.uuid, {
                onSuccess: () => {
                  toast.success(`${source.name} deleted`);
                  onClose();
                },
                onError: (error) =>
                  toast.error('Could not delete it', error.message),
              });
            }}
          >
            Delete
          </Button>
        </>
      }
    >
      <p className="flex items-start gap-2 text-xs leading-relaxed text-content-secondary">
        <Library size={14} className="mt-px shrink-0" aria-hidden="true" />
        The original content is untouched. Only what was indexed here is
        removed, and it can be indexed again.
      </p>
    </Modal>
  );
}
