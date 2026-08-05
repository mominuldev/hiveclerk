import { useState } from 'react';
import { ChevronLeft, ExternalLink } from 'lucide-react';
import { Drawer } from '@/components/ui/Drawer';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Skeleton } from '@/components/ui/Skeleton';
import { Pagination } from '@/components/ui/Pagination';
import { EmptyState } from '@/components/ui/EmptyState';
import {
  useChunks,
  useDocuments,
  type KnowledgeSource,
} from '@/api/queries/useKnowledge';

interface SourceInspectorProps {
  source: KnowledgeSource | null;
  onClose: () => void;
}

/**
 * What actually got indexed.
 *
 * This is the screen that answers "why did the clerk not know that". The
 * usual causes — a page that produced no text, a chunk that split an
 * answer in half, a heading path that lost its context — are all visible
 * here and invisible everywhere else. Retrieval quality is decided by
 * these boundaries, and without a way to look at them a bad answer is
 * unexplainable.
 */
export function SourceInspector({ source, onClose }: SourceInspectorProps) {
  const [page, setPage] = useState(1);
  const [documentId, setDocumentId] = useState<number | null>(null);

  const documents = useDocuments(source?.uuid ?? null, page);
  const chunks = useChunks(documentId);

  const close = () => {
    setDocumentId(null);
    setPage(1);
    onClose();
  };

  return (
    <Drawer
      open={source !== null}
      onClose={close}
      title={source?.name ?? ''}
      {...(documentId === null
        ? {
            subtitle: `${(source?.document_count ?? 0).toLocaleString()} documents · ${(source?.chunk_count ?? 0).toLocaleString()} chunks`,
          }
        : {})}
      width="lg"
    >
      {documentId !== null ? (
        <div className="space-y-4">
          <Button variant="link" size="sm" onClick={() => setDocumentId(null)}>
            <ChevronLeft size={14} aria-hidden="true" />
            All documents
          </Button>

          {chunks.isPending ? (
            <div className="space-y-3">
              {[0, 1, 2].map((i) => (
                <Skeleton key={i} className="h-24 w-full rounded-lg" />
              ))}
            </div>
          ) : chunks.data ? (
            <>
              <div>
                <h3 className="text-sm font-semibold text-content">
                  {chunks.data.document.title}
                </h3>
                {chunks.data.document.url && (
                  <a
                    href={chunks.data.document.url}
                    target="_blank"
                    rel="noreferrer"
                    className="mt-0.5 inline-flex items-center gap-1 text-xs text-accent-text hover:underline"
                  >
                    {chunks.data.document.url}
                    <ExternalLink size={11} aria-hidden="true" />
                  </a>
                )}
              </div>

              <div className="space-y-2.5">
                {chunks.data.chunks.map((chunk) => (
                  <article
                    key={chunk.id}
                    className="rounded-lg border border-border bg-surface-sunken p-3"
                  >
                    <header className="mb-2 flex flex-wrap items-center gap-2">
                      <Badge tone="neutral">#{chunk.index + 1}</Badge>
                      {chunk.heading_path.length > 0 && (
                        <span className="truncate text-[11px] text-content-tertiary">
                          {chunk.heading_path.join(' › ')}
                        </span>
                      )}
                      <span className="ml-auto text-[11px] tabular-nums text-content-tertiary">
                        {chunk.token_count} tokens · {chunk.char_start}–
                        {chunk.char_end}
                      </span>
                    </header>

                    <p className="whitespace-pre-wrap text-xs leading-relaxed text-content-secondary">
                      {chunk.content}
                    </p>
                  </article>
                ))}
              </div>
            </>
          ) : null}
        </div>
      ) : documents.isPending ? (
        <div className="space-y-2">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-12 w-full rounded-lg" />
          ))}
        </div>
      ) : !documents.data || documents.data.documents.length === 0 ? (
        <EmptyState
          bare
          title="Nothing indexed yet"
          description="This source has run but produced no documents. If it is a crawl, the pages may have been excluded by robots.txt."
        />
      ) : (
        <div className="space-y-3">
          <ul className="space-y-1.5">
            {documents.data.documents.map((document) => (
              <li key={document.id}>
                <button
                  type="button"
                  onClick={() => setDocumentId(document.id)}
                  className="flex w-full items-center justify-between gap-3 rounded-lg border border-border bg-surface px-3 py-2 text-left transition-colors hover:border-border-strong hover:bg-surface-hover"
                >
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm text-content">
                      {document.title || 'Untitled'}
                    </span>
                    {document.url && (
                      <span className="block truncate text-[11px] text-content-tertiary">
                        {document.url}
                      </span>
                    )}
                  </span>

                  <span className="shrink-0 text-[11px] tabular-nums text-content-tertiary">
                    {document.chunk_count}{' '}
                    {document.chunk_count === 1 ? 'chunk' : 'chunks'}
                  </span>
                </button>
              </li>
            ))}
          </ul>

          {documents.data.totalPages > 1 && (
            <Pagination
              meta={{
                page,
                per_page: 25,
                total: documents.data.total,
                total_pages: documents.data.totalPages,
              }}
              onChange={setPage}
              noun="document"
            />
          )}
        </div>
      )}
    </Drawer>
  );
}
