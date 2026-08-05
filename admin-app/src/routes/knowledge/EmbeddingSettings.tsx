import { useState } from 'react';
import { AlertTriangle, Check, Database, Info } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Card } from '@/components/ui/Card';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Field, Select } from '@/components/ui/Field';
import { Skeleton } from '@/components/ui/Skeleton';
import { toast } from '@/components/ui/Toast';
import { boot } from '@/boot';
import {
  useEmbeddingSettings,
  useRetrievalStatus,
  useSaveEmbedding,
} from '@/api/queries/useRetrieval';

/**
 * Which model turns content into vectors, and what that means.
 *
 * The screen exists because the choice is not reversible for free.
 * Vectors from two different models occupy unrelated spaces, so changing
 * this invalidates every vector on the site and bills a full re-index to
 * the customer's provider account. Saying so before the change, and
 * showing exactly how many sources it affects, is the difference between
 * a decision and a surprise.
 */
export function EmbeddingSettings() {
  const settings = useEmbeddingSettings();
  const status = useRetrievalStatus();
  const save = useSaveEmbedding();

  const [provider, setProvider] = useState<string | null>(null);
  const [model, setModel] = useState<string | null>(null);

  // shop_manager holds manage_knowledge but never manage_settings, which
  // is the role that must not be able to spend money on a re-index. The
  // server enforces it; the form hides the button so the refusal is not
  // the first they hear of it.
  const canWrite = boot().capabilities.hiveclerk_manage_settings;

  if (settings.isError) {
    return (
      <ErrorNotice error={settings.error} onRetry={() => void settings.refetch()} />
    );
  }

  if (settings.isPending) {
    return <Skeleton className="h-56 w-full rounded-xl" />;
  }

  const configured = settings.data.configured;
  const usable = settings.data.providers.filter((p) => p.ready);

  const selectedProvider = provider ?? configured?.provider ?? usable[0]?.id ?? '';
  const models =
    settings.data.providers.find((p) => p.id === selectedProvider)?.models ?? [];
  const selectedModel = model ?? configured?.model ?? models[0]?.id ?? '';

  const isDirty =
    selectedProvider !== configured?.provider || selectedModel !== configured?.model;

  return (
    <div className="space-y-4">
      {usable.length === 0 ? (
        <Card className="!p-4">
          <p className="flex items-start gap-2 text-sm leading-relaxed text-content-secondary">
            <AlertTriangle size={15} className="mt-px shrink-0 text-warning" aria-hidden="true" />
            No provider on this site can produce embeddings. Anthropic does not
            offer an embedding model, and OpenRouter has no embeddings endpoint —
            add an OpenAI, Google or Azure key under Settings → Providers.
          </p>
        </Card>
      ) : (
        <Card className="!p-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field
              label="Provider"
              hint="Chosen independently of the chat provider — a clerk can think with one model and search with another."
            >
              {({ id, describedBy }) => (
                <Select
                  id={id}
                  aria-describedby={describedBy}
                  value={selectedProvider}
                  disabled={!canWrite}
                  onChange={(e) => {
                    setProvider(e.target.value);
                    setModel(null);
                  }}
                >
                  {usable.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.label}
                    </option>
                  ))}
                </Select>
              )}
            </Field>

            <Field
              label="Model"
              hint={`Vectors wider than ${settings.data.max_dimensions.toLocaleString()} dimensions cannot be stored; wide models are asked for a shorter vector.`}
            >
              {({ id, describedBy }) => (
                <Select
                  id={id}
                  aria-describedby={describedBy}
                  value={selectedModel}
                  disabled={!canWrite || models.length === 0}
                  onChange={(e) => setModel(e.target.value)}
                >
                  {models.length === 0 && (
                    <option value="">No embedding models reported</option>
                  )}
                  {models.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.label}
                      {m.dimensions > 0 ? ` · ${m.dimensions}d` : ''}
                      {m.pricing
                        ? ` · $${m.pricing.input_per_million}/M tokens`
                        : ' · price unknown'}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          </div>

          {!settings.data.is_explicit && configured && (
            <p className="mt-3 flex items-start gap-2 text-xs leading-relaxed text-content-tertiary">
              <Info size={13} className="mt-px shrink-0" aria-hidden="true" />
              Nothing was chosen here, so the first configured provider that can
              embed is being used — {configured.provider} / {configured.model}.
              Saving records the choice explicitly.
            </p>
          )}

          {canWrite && (
            <div className="mt-4 flex items-center gap-3">
              <Button
                variant="primary"
                disabled={!isDirty || selectedModel === ''}
                loading={save.isPending}
                onClick={() =>
                  save.mutate(
                    { provider: selectedProvider, model: selectedModel },
                    {
                      onSuccess: (result) => {
                        toast.success(
                          'Embedding model saved',
                          result.message ??
                            'Nothing indexed yet needs re-embedding.'
                        );
                      },
                      onError: (error) =>
                        toast.error('Could not save it', error.message),
                    }
                  )
                }
              >
                <Check size={15} aria-hidden="true" />
                Save
              </Button>

              {isDirty && (
                <p className="text-xs leading-relaxed text-content-secondary">
                  Changing this marks everything already indexed as needing a
                  re-index. Existing vectors keep working until you run one.
                </p>
              )}
            </div>
          )}
        </Card>
      )}

      <VectorStatus />
    </div>
  );

  function VectorStatus() {
    if (status.isPending) {
      return <Skeleton className="h-32 w-full rounded-xl" />;
    }

    if (!status.data) {
      return null;
    }

    const { store, sources } = status.data;

    return (
      <Card className="!p-4">
        <h2 className="text-sm font-semibold text-content">Vector index</h2>

        <dl className="mt-2 grid gap-x-6 gap-y-1 text-xs sm:grid-cols-2">
          <Row label="Storage" value={`${store.driver} · ${store.quantisation}`} />
          <Row
            label="Distance"
            value={`popcount via ${store.popcount === 'gmp' ? 'GMP (fast path)' : 'lookup table'}`}
          />
          <Row
            label="Index cache"
            value={store.cache.persistent ? 'persistent object cache' : 'database transient'}
          />
          <Row
            label="Maximum width"
            value={`${store.max_dimensions.toLocaleString()} dimensions`}
          />
        </dl>

        {store.cache.note && (
          <p className="mt-3 flex items-start gap-2 rounded-lg border border-border bg-surface-sunken px-3 py-2 text-xs leading-relaxed text-content-secondary">
            <Database size={13} className="mt-px shrink-0" aria-hidden="true" />
            {store.cache.note}
          </p>
        )}

        {sources.length > 0 && (
          <ul className="mt-3 space-y-1.5">
            {sources.map((source) => (
              <li
                key={source.id}
                className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-border px-3 py-2 text-xs"
              >
                <span className="font-medium text-content">{source.name}</span>

                <span className="tabular-nums text-content-tertiary">
                  {source.vectors.toLocaleString()} of{' '}
                  {source.chunk_count.toLocaleString()} chunks embedded
                </span>

                {source.pinned && (
                  <span className="text-content-tertiary">
                    {source.pinned.provider} / {source.pinned.model}
                    {source.pinned.dimensions > 0
                      ? ` · ${source.pinned.dimensions}d`
                      : ''}
                  </span>
                )}

                <span className="ml-auto">
                  {source.searchable ? (
                    <Badge tone="positive">Searchable</Badge>
                  ) : source.chunk_count > 0 ? (
                    <Badge tone="warning">Not embedded</Badge>
                  ) : (
                    <Badge tone="neutral">Empty</Badge>
                  )}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    );
  }
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex gap-2">
      <dt className="text-content-tertiary">{label}</dt>
      <dd className="text-content-secondary">{value}</dd>
    </div>
  );
}
