import { useState } from 'react';
import {
  ChevronDown,
  ExternalLink,
  FileLock2,
  Plug,
  RefreshCw,
  Trash2,
} from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Field, Input, Select } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { toast } from '@/components/ui/Toast';
import { formatPerMillion, formatTimestamp } from '@/lib/format';
import {
  useProviderModels,
  useRemoveProvider,
  useSaveProvider,
  useVerifyProvider,
  type ProviderState,
} from '@/api/queries/useProviders';
import { cn } from '@/lib/cn';

interface ProviderCardProps {
  provider: ProviderState;
  /** Open on first render. Exactly one card gets this. */
  defaultOpen?: boolean;
}

/**
 * One provider's credentials and model choice.
 *
 * The flow is deliberately verify-then-save rather than save-then-verify.
 * A key that is stored before it is checked leaves the site in a state it
 * believes is working, and the first person to find out otherwise is a
 * visitor whose question went unanswered.
 *
 * The key field is never populated. The server has no endpoint that
 * returns one, so an empty field beside the stored mask is the honest
 * representation: leaving it blank keeps the existing key, typing in it
 * replaces the key.
 */
export function ProviderCard({
  provider,
  defaultOpen = false,
}: ProviderCardProps) {
  const [open, setOpen] = useState(defaultOpen);
  const [apiKey, setApiKey] = useState('');
  const [endpoint, setEndpoint] = useState(provider.endpoint);
  const [apiVersion, setApiVersion] = useState(provider.api_version);
  const [model, setModel] = useState(provider.model);
  const [confirmRemove, setConfirmRemove] = useState(false);

  const models = useProviderModels(provider.provider, provider.is_set && open);
  const save = useSaveProvider();
  const verify = useVerifyProvider();
  const remove = useRemoveProvider();

  const needsEndpoint = provider.capabilities.needs_endpoint;
  const endpointMissing = needsEndpoint && endpoint.trim() === '';
  const canVerify = (apiKey.trim() !== '' || provider.is_set) && !endpointMissing;

  const onVerify = () => {
    verify.mutate(
      {
        provider: provider.provider,
        ...(apiKey.trim() ? { api_key: apiKey.trim() } : {}),
        ...(endpoint.trim() ? { endpoint: endpoint.trim() } : {}),
        ...(apiVersion.trim() ? { api_version: apiVersion.trim() } : {}),
      },
      {
        onSuccess: (result) => {
          if (result.ok) {
            toast.success(
              `${provider.label} connected.`,
              `${result.model_count} models available · ${result.latency_ms} ms`
            );
          } else {
            // The provider's own wording, not ours. "Rejected: your credit
            // balance is too low" tells an operator what to do; "connection
            // failed" does not.
            toast.error(`${provider.label} rejected the key.`, result.message);
          }
        },
        onError: (error) => toast.error('Could not check the key.', error.message),
      }
    );
  };

  const onSave = () => {
    save.mutate(
      {
        provider: provider.provider,
        ...(apiKey.trim() ? { api_key: apiKey.trim() } : {}),
        ...(endpoint.trim() ? { endpoint: endpoint.trim() } : {}),
        ...(apiVersion.trim() ? { api_version: apiVersion.trim() } : {}),
        ...(model ? { model } : {}),
      },
      {
        onSuccess: () => {
          setApiKey('');
          toast.success(`${provider.label} saved.`);
        },
        onError: (error) =>
          toast.error(
            'Could not save.',
            error.fieldErrors.api_key?.[0] ?? error.message
          ),
      }
    );
  };

  return (
    <section className="overflow-hidden rounded-xl border border-border bg-surface [box-shadow:var(--hvc-elevate),var(--hvc-shadow-sm)]">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        className="flex w-full items-center gap-3 px-4 py-3.5 text-left transition-colors hover:bg-surface-hover"
      >
        <ChevronDown
          size={15}
          aria-hidden="true"
          className={cn(
            'shrink-0 text-content-tertiary transition-transform duration-[var(--hvc-duration-base)]',
            open && 'rotate-180'
          )}
        />

        <span className="min-w-0 flex-1">
          <span className="block font-medium text-content">{provider.label}</span>
          <span className="block truncate font-mono text-xs text-content-tertiary">
            {provider.is_set ? provider.masked : 'No key set'}
            {provider.model && ` · ${provider.model}`}
          </span>
        </span>

        <ProviderStatus provider={provider} />
      </button>

      {open && (
        <div className="space-y-4 border-t border-border px-4 py-4">
          {provider.from_config ? (
            <div className="flex items-start gap-2 rounded-lg border border-border bg-surface-sunken px-3 py-2.5">
              <FileLock2
                size={14}
                aria-hidden="true"
                className="mt-px shrink-0 text-content-tertiary"
              />
              <p className="text-xs leading-relaxed text-content-secondary">
                This key comes from{' '}
                <span className="font-mono">
                  HIVECLERK_{provider.provider.toUpperCase()}_KEY
                </span>{' '}
                in wp-config.php, so it is not stored in the database and cannot
                be changed here. Remove the constant to manage it from this
                screen.
              </p>
            </div>
          ) : (
            <Field
              label="API key"
              hint={
                provider.is_set
                  ? 'Leave blank to keep the current key. Typing replaces it.'
                  : `Pasted over HTTPS, encrypted immediately, and never shown again.${
                      provider.key_hint ? ` Usually starts ${provider.key_hint.replace('^', '')}.` : ''
                    }`
              }
              aside={
                provider.console_url ? (
                  <a
                    href={provider.console_url}
                    target="_blank"
                    rel="noreferrer noopener"
                    className="flex items-center gap-1 text-xs text-accent-text hover:underline"
                  >
                    Get a key
                    <ExternalLink size={11} aria-hidden="true" />
                  </a>
                ) : undefined
              }
            >
              {({ id, describedBy }) => (
                <Input
                  id={id}
                  aria-describedby={describedBy}
                  type="password"
                  mono
                  autoComplete="off"
                  spellCheck={false}
                  value={apiKey}
                  onChange={(event) => setApiKey(event.target.value)}
                  placeholder={provider.is_set ? provider.masked : 'Paste your key'}
                />
              )}
            </Field>
          )}

          {needsEndpoint && (
            <div className="grid gap-4 sm:grid-cols-2">
              <Field
                label="Resource endpoint"
                hint="The https:// URL of your Azure OpenAI resource."
                {...(endpointMissing ? { error: 'Azure needs an endpoint.' } : {})}
              >
                {({ id, describedBy }) => (
                  <Input
                    id={id}
                    aria-describedby={describedBy}
                    mono
                    invalid={endpointMissing}
                    value={endpoint}
                    onChange={(event) => setEndpoint(event.target.value)}
                    placeholder="https://my-resource.openai.azure.com"
                  />
                )}
              </Field>

              <Field
                label="API version"
                hint="Leave blank for the default Azure validates against."
              >
                {({ id, describedBy }) => (
                  <Input
                    id={id}
                    aria-describedby={describedBy}
                    mono
                    value={apiVersion}
                    onChange={(event) => setApiVersion(event.target.value)}
                    placeholder="2024-10-21"
                  />
                )}
              </Field>
            </div>
          )}

          {provider.is_set && (
            <Field
              label="Model"
              hint={
                models.isError
                  ? 'Could not read the model list. Check the key, then try again.'
                  : 'Only models this account can actually reach are listed.'
              }
              aside={
                <button
                  type="button"
                  onClick={() => void models.refetch()}
                  className="flex items-center gap-1 text-xs text-content-tertiary transition-colors hover:text-content"
                >
                  <RefreshCw
                    size={11}
                    aria-hidden="true"
                    className={cn(models.isFetching && 'animate-spin')}
                  />
                  Refresh
                </button>
              }
            >
              {({ id, describedBy }) => (
                <Select
                  id={id}
                  aria-describedby={describedBy}
                  value={model}
                  disabled={models.isPending || models.isError}
                  onChange={(event) => setModel(event.target.value)}
                >
                  <option value="">
                    {models.isPending
                      ? 'Loading models…'
                      : models.isError
                        ? 'Unavailable'
                        : `Default (${provider.default_model || 'none'})`}
                  </option>
                  {(models.data ?? []).map((entry) => (
                    <option key={entry.id} value={entry.id}>
                      {entry.label}
                      {entry.pricing
                        ? ` — ${formatPerMillion(entry.pricing.input_per_million, entry.pricing.output_per_million)}`
                        : ' — price not published'}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          )}

          <div className="flex flex-wrap items-center gap-2 pt-1">
            <Button
              variant="primary"
              size="sm"
              onClick={onSave}
              loading={save.isPending}
              disabled={provider.from_config && !model}
            >
              Save
            </Button>

            <Button
              size="sm"
              onClick={onVerify}
              loading={verify.isPending}
              disabled={!canVerify}
              icon={<Plug size={14} aria-hidden="true" />}
            >
              Test connection
            </Button>

            {provider.is_set && !provider.from_config && (
              <Button
                variant="ghost"
                size="sm"
                className="ml-auto text-danger hover:bg-danger/10 hover:text-danger"
                onClick={() => setConfirmRemove(true)}
                icon={<Trash2 size={14} aria-hidden="true" />}
              >
                Remove key
              </Button>
            )}
          </div>
        </div>
      )}

      <Modal
        open={confirmRemove}
        onClose={() => setConfirmRemove(false)}
        title={`Remove the ${provider.label} key?`}
        description="Any clerk using this provider stops answering until another key is added. Conversations and knowledge are not affected."
        size="sm"
        danger
        footer={
          <>
            <Button size="sm" onClick={() => setConfirmRemove(false)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              size="sm"
              loading={remove.isPending}
              onClick={() =>
                remove.mutate(provider.provider, {
                  onSuccess: () => {
                    setConfirmRemove(false);
                    setApiKey('');
                    toast.success(`${provider.label} key removed.`);
                  },
                  onError: (error) =>
                    toast.error('Could not remove the key.', error.message),
                })
              }
            >
              Remove
            </Button>
          </>
        }
      />
    </section>
  );
}

/**
 * The badge on the collapsed row.
 *
 * "Not tested" is a distinct state from "connected" on purpose. A stored
 * key that has never been checked is exactly the situation that produces a
 * site which looks configured and answers nothing.
 */
function ProviderStatus({ provider }: { provider: ProviderState }) {
  if (!provider.is_set) {
    return <Badge>Not set</Badge>;
  }

  if (!provider.verified_at) {
    return <Badge tone="warning">Not tested</Badge>;
  }

  return (
    <Badge tone="positive" className="whitespace-nowrap">
      Connected · {formatTimestamp(provider.verified_at).slice(0, 10)}
    </Badge>
  );
}
