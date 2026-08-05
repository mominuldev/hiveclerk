import { useState, type FormEvent } from 'react';
import {
  AlertTriangle,
  Database,
  ExternalLink,
  Search,
  Zap,
} from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Field, Input, Select } from '@/components/ui/Field';
import { Skeleton } from '@/components/ui/Skeleton';
import { cn } from '@/lib/cn';
import {
  useRetrievalStatus,
  useSearch,
  type RetrievalDiagnostics,
  type SearchResult,
} from '@/api/queries/useRetrieval';

const DEFAULT_THRESHOLD = 0.62;

/**
 * Retrieval, made debuggable (FR-KB-12).
 *
 * The screen answers the question an operator otherwise has no way to
 * ask: *why did my clerk say that?* Without it the only available theory
 * is that the model is bad, which is almost never what happened — the
 * usual causes are a chunk boundary that split an answer, content that
 * was never indexed, or a match that scored just under the threshold.
 * All three are visible here and invisible everywhere else.
 */
export function Playground() {
  const status = useRetrievalStatus();
  const search = useSearch();

  const [query, setQuery] = useState('');
  const [sourceId, setSourceId] = useState<'all' | number>('all');
  const [topK, setTopK] = useState(10);
  const [threshold, setThreshold] = useState(DEFAULT_THRESHOLD);
  const [useKeyword, setUseKeyword] = useState(true);

  const searchable = (status.data?.sources ?? []).filter((s) => s.searchable);
  const cache = status.data?.store.cache;

  const submit = (event: FormEvent) => {
    event.preventDefault();

    if (query.trim() === '') return;

    search.mutate({
      query: query.trim(),
      top_k: topK,
      threshold,
      use_keyword: useKeyword,
      // Always a real search. A cached result would report a
      // two-millisecond retrieval that did none of the work the timings
      // claim to be measuring, which is the one thing this screen exists
      // not to do.
      fresh: true,
      ...(sourceId === 'all' ? {} : { source_ids: [sourceId] }),
    });
  };

  if (status.isError) {
    return <ErrorNotice error={status.error} onRetry={() => void status.refetch()} />;
  }

  if (status.isPending) {
    return <Skeleton className="h-40 w-full rounded-xl" />;
  }

  if (searchable.length === 0) {
    return (
      <EmptyState
        title="Nothing is searchable yet"
        description="A source has to be indexed and embedded before it can be searched. Add a knowledge source, then come back once its vectors are built."
      />
    );
  }

  return (
    <div className="space-y-4">
      <Card className="!p-4">
        <form onSubmit={submit} className="space-y-4">
          <div className="flex flex-wrap items-end gap-3">
            <div className="min-w-[16rem] flex-1">
              <Field
                label="Ask what a visitor would ask"
                hint="The question is embedded and searched exactly as a clerk would search it."
              >
                {({ id, describedBy }) => (
                  <Input
                    id={id}
                    aria-describedby={describedBy}
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="international shipping cost"
                    autoComplete="off"
                  />
                )}
              </Field>
            </div>

            <Button
              type="submit"
              variant="primary"
              loading={search.isPending}
              disabled={query.trim() === ''}
            >
              <Search size={15} aria-hidden="true" />
              Search
            </Button>
          </div>

          <div className="grid gap-3 sm:grid-cols-3">
            <Field label="Sources">
              {({ id }) => (
                <Select
                  id={id}
                  value={String(sourceId)}
                  onChange={(e) =>
                    setSourceId(
                      e.target.value === 'all' ? 'all' : Number(e.target.value)
                    )
                  }
                >
                  <option value="all">
                    All searchable sources ({searchable.length})
                  </option>
                  {searchable.map((source) => (
                    <option key={source.id} value={source.id}>
                      {source.name} · {source.vectors.toLocaleString()} vectors
                    </option>
                  ))}
                </Select>
              )}
            </Field>

            <Field label="Results">
              {({ id }) => (
                <Select
                  id={id}
                  value={String(topK)}
                  onChange={(e) => setTopK(Number(e.target.value))}
                >
                  {[5, 10, 20, 50].map((n) => (
                    <option key={n} value={n}>
                      Top {n}
                    </option>
                  ))}
                </Select>
              )}
            </Field>

            <Field
              label="Confidence threshold"
              hint="Applied to the cosine score, not the fused rank."
            >
              {({ id, describedBy }) => (
                <Input
                  id={id}
                  aria-describedby={describedBy}
                  type="number"
                  min={0}
                  max={1}
                  step={0.01}
                  value={threshold}
                  onChange={(e) => setThreshold(Number(e.target.value))}
                />
              )}
            </Field>
          </div>

          <label className="flex w-fit items-center gap-2 text-xs text-content-secondary">
            <input
              type="checkbox"
              checked={useKeyword}
              onChange={(e) => setUseKeyword(e.target.checked)}
              className="size-3.5 accent-accent"
            />
            Fuse with keyword search — turn off to see what the vectors find
            alone
          </label>
        </form>
      </Card>

      {cache && !cache.persistent && (
        <p className="flex items-start gap-2 rounded-lg border border-border bg-surface-sunken px-3 py-2 text-xs leading-relaxed text-content-secondary">
          <Database size={13} className="mt-px shrink-0" aria-hidden="true" />
          {cache.note}
        </p>
      )}

      {search.isError && <ErrorNotice error={search.error} />}

      {search.data && (
        <Results
          results={search.data.results}
          diagnostics={search.data.diagnostics}
          threshold={search.data.threshold}
          embedding={search.data.embedding}
        />
      )}
    </div>
  );
}

interface ResultsProps {
  results: SearchResult[];
  diagnostics: Partial<RetrievalDiagnostics>;
  threshold: number;
  embedding: { provider: string; model: string } | null;
}

function Results({ results, diagnostics, threshold, embedding }: ResultsProps) {
  if (results.length === 0) {
    return (
      <EmptyState
        bare
        title="Nothing came back"
        description="No chunk matched by meaning or by keyword. Either the content is not indexed, or the question is about something the knowledge base does not cover."
      />
    );
  }

  /*
   * Where the threshold line goes: after the last result whose cosine is
   * still above it. Drawing it at a fixed position would be decoration;
   * drawn here it marks exactly the boundary a clerk will apply.
   */
  const lastConfident = results.reduce(
    (last, result, index) => (result.confident ? index : last),
    -1
  );

  return (
    <div className="space-y-3">
      <Timings diagnostics={diagnostics} embedding={embedding} />

      {(diagnostics.notes ?? []).map((note) => (
        <p
          key={note}
          className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs leading-relaxed text-content-secondary"
        >
          <AlertTriangle size={13} className="mt-px shrink-0" aria-hidden="true" />
          {note}
        </p>
      ))}

      <ol className="space-y-2">
        {results.map((result, index) => (
          <li key={result.chunk_id}>
            <ResultRow result={result} />

            {index === lastConfident && index < results.length - 1 && (
              <ThresholdLine threshold={threshold} />
            )}
          </li>
        ))}
      </ol>

      {lastConfident === -1 && (
        <ThresholdLine threshold={threshold} everythingBelow />
      )}
    </div>
  );
}

function ThresholdLine({
  threshold,
  everythingBelow = false,
}: {
  threshold: number;
  everythingBelow?: boolean;
}) {
  return (
    <div className="flex items-center gap-3 py-2" aria-hidden="true">
      <span className="h-px flex-1 bg-border-strong" />
      <span className="font-mono text-[11px] tabular-nums text-content-tertiary">
        {everythingBelow
          ? `nothing reached the ${threshold.toFixed(2)} threshold`
          : `threshold ${threshold.toFixed(2)}`}
      </span>
      <span className="h-px flex-1 bg-border-strong" />
    </div>
  );
}

function ResultRow({ result }: { result: SearchResult }) {
  /*
   * A single-page document usually has its own <h1> as the first heading,
   * so the chunk's path is the title again. Printing "Hello world! ›
   * Hello world!" spends the row's most valuable space saying nothing.
   */
  const path = result.heading_path.filter(
    (heading) => heading !== result.document_title
  );

  return (
    <article
      className={cn(
        'rounded-lg border bg-surface p-3',
        result.confident
          ? 'border-border'
          : // Below the threshold is not an error, it is content the clerk
            // will not use. Dimmed rather than badged, so the eye reads the
            // list as one ranked set with a cut-off in it.
            'border-border/60 opacity-70'
      )}
    >
      <header className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
        <span className="font-mono text-[11px] tabular-nums text-content-tertiary">
          {result.rank}
        </span>

        <span className="text-sm font-medium text-content">
          {result.document_title || 'Untitled'}
        </span>

        {path.length > 0 && (
          <span className="truncate text-xs text-content-tertiary">
            › {path.join(' › ')}
          </span>
        )}

        {result.document_url && (
          <a
            href={result.document_url}
            target="_blank"
            rel="noreferrer"
            className="text-content-tertiary transition-colors hover:text-accent-text"
            aria-label={`Open ${result.document_title || 'the source page'}`}
          >
            <ExternalLink size={12} aria-hidden="true" />
          </a>
        )}

        <span className="ml-auto flex items-center gap-3 font-mono text-[11px] tabular-nums">
          <Score
            label="cosine"
            value={result.vector_score.toFixed(3)}
            rank={result.vector_rank}
            emphasis={result.confident}
          />
          <Score
            label="bm25"
            value={result.bm25_score.toFixed(2)}
            rank={result.keyword_rank}
          />
          <Score label="rrf" value={result.fused_score.toFixed(4)} rank={null} />
        </span>
      </header>

      <p className="mt-1.5 text-xs leading-relaxed text-content-secondary">
        {result.excerpt}
      </p>

      <footer className="mt-1.5 flex flex-wrap items-center gap-2 text-[11px] text-content-tertiary">
        <Badge tone="neutral">chunk {result.chunk_id}</Badge>
        <span className="tabular-nums">{result.token_count} tokens</span>
        {result.vector_rank === null && (
          // Found by keyword and not by the vector search at all. Worth
          // naming: it is the case that justifies running both.
          <span>keyword only</span>
        )}
        {result.keyword_rank === null && <span>vector only</span>}
      </footer>
    </article>
  );
}

function Score({
  label,
  value,
  rank,
  emphasis = false,
}: {
  label: string;
  value: string;
  rank: number | null;
  emphasis?: boolean;
}) {
  return (
    <span
      className={cn(
        'flex items-baseline gap-1',
        emphasis ? 'text-content' : 'text-content-tertiary'
      )}
      title={rank === null ? `${label}: not ranked by this signal` : `${label} rank ${rank}`}
    >
      <span className="text-content-tertiary">{label}</span>
      <span>{value}</span>
      {rank !== null && <span className="text-content-tertiary">#{rank}</span>}
    </span>
  );
}

function Timings({
  diagnostics,
  embedding,
}: {
  diagnostics: Partial<RetrievalDiagnostics>;
  embedding: { provider: string; model: string } | null;
}) {
  const stages = [
    { label: 'embed query', ms: diagnostics.embed_ms },
    { label: 'stage 1 coarse', ms: diagnostics.stage1_ms },
    { label: 'stage 2 exact', ms: diagnostics.stage2_ms },
    { label: 'keyword', ms: diagnostics.keyword_ms },
    { label: 'fuse', ms: diagnostics.fusion_ms },
  ].filter((stage) => typeof stage.ms === 'number');

  return (
    <div className="rounded-lg border border-border bg-surface-sunken px-3 py-2.5">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 font-mono text-[11px] tabular-nums text-content-tertiary">
        <span className="flex items-center gap-1.5 text-content">
          <Zap size={12} aria-hidden="true" />
          {(diagnostics.total_ms ?? 0).toFixed(0)} ms total
        </span>

        {stages.map((stage) => (
          <span key={stage.label}>
            {stage.label} {(stage.ms ?? 0).toFixed(1)}
          </span>
        ))}
      </div>

      <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-content-tertiary">
        <span>
          scanned {(diagnostics.scanned ?? 0).toLocaleString()} · kept{' '}
          {(diagnostics.candidates ?? 0).toLocaleString()} · keyword matched{' '}
          {(diagnostics.keyword_matches ?? 0).toLocaleString()}
        </span>
        <span>{diagnostics.strategy}</span>
        <span>matrix from {diagnostics.matrix_source}</span>
        <span>popcount {diagnostics.popcount}</span>
        {embedding && (
          <span>
            {embedding.provider} / {embedding.model}
          </span>
        )}
      </div>
    </div>
  );
}
