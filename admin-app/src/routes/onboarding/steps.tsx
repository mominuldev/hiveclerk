import { useEffect, useState } from 'react';
import { Check, Loader2, ShieldCheck } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Field, Input, Select } from '@/components/ui/Field';
import { Skeleton } from '@/components/ui/Skeleton';
import { toast } from '@/components/ui/Toast';
import {
  useProviders,
  useSaveProvider,
  useVerifyProvider,
} from '@/api/queries/useProviders';
import { useCreateAgent, usePresets } from '@/api/queries/useAgents';
import { useCreateSource } from '@/api/queries/useKnowledge';
import { useDetectSources, type DetectedSource } from '@/api/queries/useOnboarding';
import { formatCost } from '@/lib/format';
import { cn } from '@/lib/cn';

export interface StepProps {
  /** Records the step and moves on. */
  onDone: (payload?: { agent?: string; sources?: string[]; choice?: string }) => void;
  /** The clerk this run created, once step 2 has run. */
  agentUuid: string | null;
  busy: boolean;
}

/* -------------------------------------------------------------------------
 * Step 1 — Model
 * ---------------------------------------------------------------------- */

/**
 * Connect a provider and prove the key works (FR-ONB-02, 03).
 *
 * The key is verified with a live call before the step can be completed.
 * A wizard that accepted an unverified key would hand the customer a
 * clerk that fails on its first real conversation, in front of a
 * visitor, with no obvious cause.
 */
export function StepModel({ onDone, busy }: StepProps) {
  const { data, isPending } = useProviders();
  const save = useSaveProvider();
  const verify = useVerifyProvider();

  const [provider, setProvider] = useState('anthropic');
  const [key, setKey] = useState('');
  const [verified, setVerified] = useState(false);

  const providers = data?.providers ?? [];
  const already = providers.find((p) => p.is_set && '' !== p.verified_at);

  useEffect(() => {
    if (already) {
      setProvider(already.provider);
      setVerified(true);
    }
  }, [already]);

  if (isPending) {
    return <Skeleton className="h-56 w-full rounded-xl" />;
  }

  const connect = () => {
    verify.mutate(
      { provider, api_key: key },
      {
        onSuccess: (result) => {
          if (!result.ok) {
            toast.error('That key was refused.', result.message);
            return;
          }

          save.mutate(
            { provider, api_key: key },
            {
              onSuccess: () => {
                setVerified(true);
                toast.success('Key verified and stored, encrypted.');
              },
              onError: (error) => toast.error(error.message),
            }
          );
        },
        onError: (error) => toast.error(error.message),
      }
    );
  };

  return (
    <div className="mx-auto max-w-lg">
      <h2 className="text-center font-display text-xl font-bold tracking-[-0.02em] text-content">
        Which model should your clerks use?
      </h2>
      <p className="mt-2 text-center text-sm leading-relaxed text-content-secondary">
        Your key stays on this server, encrypted. Nothing about your site is sent
        anywhere until a visitor asks a question.
      </p>

      <div className="mt-6 space-y-4">
        <Field label="Provider">
          {({ id }) => (
            <Select
              id={id}
              value={provider}
              onChange={(event) => {
                setProvider(event.target.value);
                setVerified(false);
              }}
            >
              {providers.map((option) => (
                <option key={option.provider} value={option.provider}>
                  {option.label}
                </option>
              ))}
            </Select>
          )}
        </Field>

        {verified ? (
          <p className="flex items-center gap-2 rounded-lg border border-border bg-surface-sunken px-3 py-2 text-sm text-content">
            <ShieldCheck size={15} aria-hidden="true" className="text-[var(--hvc-on-duty-ink)]" />
            This provider is connected and verified.
          </p>
        ) : (
          <Field
            label="API key"
            hint={`Pasted once, stored encrypted, never shown again.${
              // key_hint is the prefix the server validates against, not a
              // sentence. Shown as an example rather than printed raw,
              // which is what "^sk-ant-" would have been.
              providers.find((p) => p.provider === provider)?.key_hint
                ? ` Keys for this provider start ${providers
                    .find((p) => p.provider === provider)
                    ?.key_hint.replace(/^\^/, '')}`
                : ''
            }`}
          >
            {({ id, describedBy }) => (
              <Input
                id={id}
                mono
                type="password"
                autoComplete="off"
                aria-describedby={describedBy}
                value={key}
                onChange={(event) => setKey(event.target.value)}
              />
            )}
          </Field>
        )}
      </div>

      <div className="mt-6 flex justify-end gap-2">
        {!verified && (
          <Button
            variant="primary"
            loading={verify.isPending || save.isPending}
            disabled={key.trim().length < 8}
            onClick={connect}
          >
            Verify key
          </Button>
        )}

        {verified && (
          <Button variant="primary" loading={busy} onClick={() => onDone({ choice: provider })}>
            Continue
          </Button>
        )}
      </div>
    </div>
  );
}

/* -------------------------------------------------------------------------
 * Step 2 — Role
 * ---------------------------------------------------------------------- */

/**
 * Choose what the first clerk does, and hire it (D11 §12).
 *
 * Picking a role writes a whole job description, not a label. An operator
 * who starts from an empty instructions box gets a chatbot; one editing a
 * paragraph that already says what to do gets staff.
 */
export function StepRole({ onDone, busy }: StepProps) {
  const { data: presets, isPending } = usePresets();
  const create = useCreateAgent();
  const [chosen, setChosen] = useState<string | null>(null);
  const [name, setName] = useState('');

  if (isPending) {
    return <Skeleton className="h-72 w-full rounded-xl" />;
  }

  const roles = presets?.presets ?? [];
  const preset = roles.find((role) => role.key === chosen);

  const hire = () => {
    if (!preset) {
      return;
    }

    create.mutate(
      {
        name: '' === name.trim() ? preset.label : name.trim(),
        role_preset: preset.key,
        instructions: preset.instructions,
        greeting: preset.greeting,
        fallback_message: preset.fallback,
      },
      {
        onSuccess: (agent) => onDone({ agent: agent.uuid, choice: preset.key }),
        onError: (error) => toast.error(error.message),
      }
    );
  };

  return (
    <div className="mx-auto max-w-3xl">
      <h2 className="text-center font-display text-xl font-bold tracking-[-0.02em] text-content">
        What should your first clerk do?
      </h2>

      <div className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {roles.map((role) => (
          <button
            key={role.key}
            type="button"
            aria-pressed={chosen === role.key}
            onClick={() => setChosen(role.key)}
            className={cn(
              'rounded-xl border p-4 text-left transition-colors',
              'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
              chosen === role.key
                ? 'border-accent bg-accent-subtle'
                : 'border-border bg-surface hover:border-border-strong hover:bg-surface-hover'
            )}
          >
            <span className="flex items-start justify-between gap-2">
              <span className="font-display text-sm font-bold text-content">
                {role.label}
              </span>
              {chosen === role.key && (
                <Check size={14} aria-hidden="true" className="mt-0.5 shrink-0 text-accent" />
              )}
            </span>
            <span className="mt-1.5 block text-xs leading-relaxed text-content-secondary">
              {role.summary}
            </span>
          </button>
        ))}
      </div>

      {preset && (
        <div className="mx-auto mt-6 max-w-md">
          <Field
            label="Give them a name"
            hint="Visitors see this. A first name reads better than “Support Bot”."
          >
            {({ id, describedBy }) => (
              <Input
                id={id}
                aria-describedby={describedBy}
                value={name}
                placeholder={preset.label}
                onChange={(event) => setName(event.target.value)}
              />
            )}
          </Field>
        </div>
      )}

      <div className="mt-6 flex justify-end">
        <Button
          variant="primary"
          loading={create.isPending || busy}
          disabled={!preset}
          onClick={hire}
        >
          Continue
        </Button>
      </div>
    </div>
  );
}

/* -------------------------------------------------------------------------
 * Step 3 — Knowledge
 * ---------------------------------------------------------------------- */

/**
 * Auto-detect what is worth indexing and index it (FR-ONB-04).
 *
 * The step that decides whether setup finishes. Asked to describe their
 * knowledge from a blank form most people close the tab; shown their own
 * content already ticked, they click Continue.
 *
 * Cost is shown before the commitment, never after.
 */
export function StepKnowledge({ onDone, agentUuid, busy }: StepProps) {
  const detect = useDetectSources();
  const create = useCreateSource();
  const [picked, setPicked] = useState<Set<string>>(new Set());
  const [ran, setRan] = useState(false);

  useEffect(() => {
    if (ran) {
      return;
    }

    setRan(true);

    detect.mutate(undefined, {
      onSuccess: (result) => {
        setPicked(
          new Set(
            result.suggestions.filter((s) => s.recommended).map((s) => s.key)
          )
        );
      },
      onError: (error) => toast.error(error.message),
    });
  }, [detect, ran]);

  if (detect.isPending || !detect.data) {
    return <Skeleton className="h-72 w-full rounded-xl" />;
  }

  const options: DetectedSource[] = [
    ...detect.data.suggestions,
    ...(detect.data.sitemap ? [detect.data.sitemap] : []),
  ];

  const selected = options.filter((option) => picked.has(option.key));
  const chunks = selected.reduce((total, option) => total + (option.chunks ?? 0), 0);
  const cost = selected.reduce(
    (total, option) => total + (option.estimated_usd ?? 0),
    0
  );
  const anyUnpriced = selected.some((option) => option.estimated_usd === null);

  const index = async () => {
    const created: string[] = [];

    for (const option of selected) {
      try {
        const source = await create.mutateAsync({
          name: option.label,
          type: option.source_type,
          config: option.url
            ? { start_url: option.url }
            : { post_types: [option.post_type ?? 'page'] },
        });

        created.push(source.uuid);
      } catch {
        // One refused source must not lose the other three. The step
        // continues and the sources screen shows what landed.
        toast.error(`Could not add "${option.label}".`);
      }
    }

    onDone({ sources: created, ...(agentUuid ? { agent: agentUuid } : {}) });
  };

  return (
    <div className="mx-auto max-w-2xl">
      <h2 className="text-center font-display text-xl font-bold tracking-[-0.02em] text-content">
        We found these on your site
      </h2>
      <p className="mt-2 text-center text-sm leading-relaxed text-content-secondary">
        Indexing runs in the background. You can publish before it finishes.
      </p>

      <ul className="mt-6 divide-y divide-border rounded-xl border border-border bg-surface">
        {options.map((option) => (
          <li key={option.key}>
            <label className="flex cursor-pointer items-center gap-3 p-3.5">
              <input
                type="checkbox"
                checked={picked.has(option.key)}
                onChange={(event) => {
                  const next = new Set(picked);
                  if (event.target.checked) {
                    next.add(option.key);
                  } else {
                    next.delete(option.key);
                  }
                  setPicked(next);
                }}
                className="h-4 w-4 shrink-0 rounded border-border-strong accent-[var(--hvc-accent)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              />
              <span className="min-w-0 flex-1 text-sm text-content">
                {option.label}
              </span>
              <span className="shrink-0 font-mono text-xs tabular-nums text-content-tertiary">
                {option.count !== undefined && `${option.count.toLocaleString()} items`}
                {option.chunks !== undefined &&
                  ` · ≈ ${option.chunks.toLocaleString()} chunks`}
              </span>
            </label>
          </li>
        ))}
      </ul>

      <p className="mt-3 text-center text-sm text-content-secondary">
        Selected:{' '}
        <span className="font-mono tabular-nums text-content">
          {chunks.toLocaleString()} chunks
        </span>
        {anyUnpriced ? (
          // Not "$0.00". No price is published for the configured
          // embedding model, and a zero would be a promise the invoice
          // breaks.
          <> · one-off cost unknown until an embedding model is chosen</>
        ) : (
          <> · one-off cost ≈ {formatCost(cost)}</>
        )}
      </p>
      <p className="mt-1 text-center text-xs text-content-tertiary">
        These are estimates from a sample of your content, not a quote.
      </p>

      <div className="mt-6 flex justify-center gap-2">
        <Button variant="ghost" onClick={() => onDone({ sources: [] })}>
          Skip for now
        </Button>
        <Button
          variant="primary"
          loading={create.isPending || busy}
          disabled={0 === selected.length}
          onClick={() => void index()}
          icon={
            create.isPending ? (
              <Loader2 size={13} aria-hidden="true" className="animate-spin motion-reduce:animate-none" />
            ) : undefined
          }
        >
          Index {selected.length} source{1 === selected.length ? '' : 's'}
        </Button>
      </div>
    </div>
  );
}
