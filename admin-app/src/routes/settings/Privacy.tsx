import { useEffect, useState } from 'react';
import { AlertTriangle } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Field, Input, Textarea } from '@/components/ui/Field';
import { Skeleton } from '@/components/ui/Skeleton';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { toast } from '@/components/ui/Toast';
import {
  usePrivacy,
  useSavePrivacy,
  type PrivacySettings,
} from '@/api/queries/usePrivacy';
import { cn } from '@/lib/cn';

/**
 * Privacy and data handling (FR-SYS-04, D11 §11).
 *
 * Every control here is wired to something real. That is worth stating
 * because it was not true before Sprint 10: two of these keys existed as
 * defaults that no code read, which meant an operator could tick a box,
 * see it stay ticked, and be wrong about their own site when a customer
 * asked them what it stored.
 *
 * The screen's other job is to show consequence before commitment.
 * Shortening retention deletes history that already exists — that is the
 * point of the setting, and it is also the thing an operator does not
 * expect — so the count of what would go is on screen before the save,
 * not in a toast afterwards.
 */
export function Privacy() {
  const { data, isPending, isError, error, refetch } = usePrivacy();
  const save = useSavePrivacy();
  const [draft, setDraft] = useState<PrivacySettings | null>(null);

  useEffect(() => {
    if (data && null === draft) {
      setDraft(data.settings);
    }
  }, [data, draft]);

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  if (isPending || !data || !draft) {
    return <Skeleton className="h-[520px] w-full rounded-xl" />;
  }

  const set = <K extends keyof PrivacySettings>(key: K, value: PrivacySettings[K]) =>
    setDraft({ ...draft, [key]: value });

  const submit = () => {
    save.mutate(draft, {
      onSuccess: (state) => {
        setDraft(state.settings);
        toast.success('Privacy settings saved.');
      },
      onError: (mutationError) => toast.error(mutationError.message),
    });
  };

  /*
   * Shown only when the operator has actually shortened the policy in
   * this session. The count is true whenever retention is on, but a
   * warning that is always present is one nobody reads by the third
   * visit.
   */
  const shortened = draft.retention_months !== 0
    && draft.retention_months < data.settings.retention_months;

  return (
    <div className="space-y-5">
      <Card
        eyebrow="Retention"
        title="How long conversations are kept"
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Months of history"
            hint={`0 keeps everything forever. The ceiling is ${data.retention.max_months} months — past that, a policy is indistinguishable from keeping everything.`}
          >
            {({ id, describedBy }) => (
              <Input
                id={id}
                type="number"
                min={0}
                max={data.retention.max_months}
                aria-describedby={describedBy}
                value={String(draft.retention_months)}
                onChange={(event) =>
                  set('retention_months', Math.max(0, Number(event.target.value) || 0))
                }
              />
            )}
          </Field>
        </div>

        <p className="mt-4 rounded-lg border border-border bg-surface-sunken px-3 py-2 text-xs leading-relaxed text-content-secondary">
          {0 === data.settings.retention_months ? (
            'Nothing is deleted on a schedule. Every conversation is kept until somebody removes it.'
          ) : (
            <>
              The next run removes{' '}
              <strong className="font-medium text-content">
                {data.retention.pending.toLocaleString()}
              </strong>{' '}
              {1 === data.retention.pending ? 'conversation' : 'conversations'} started
              before {data.retention.cutoff ?? '—'} UTC.
            </>
          )}
        </p>

        {shortened && (
          <p
            className="mt-3 flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs leading-relaxed text-content"
            role="status"
          >
            <AlertTriangle size={14} aria-hidden="true" className="mt-0.5 shrink-0" />
            <span>
              Shortening the policy deletes history that already exists, not just
              future conversations. Saving this schedules the deletion; it cannot be
              undone.
            </span>
          </p>
        )}
      </Card>

      <Card eyebrow="Visitors" title="What is recorded about the people who chat">
        <p className="mb-4 text-xs leading-relaxed text-content-secondary">
          IP addresses are never stored. What can be kept is a one-way salted hash
          of one, which identifies nobody but lets a repeat visitor be recognised
          across sessions. Turning it off does not weaken rate limiting — that
          derives its own key from the live request and has never read the stored
          column.
        </p>

        <Toggle
          checked={draft.store_ip_hash}
          onChange={(value) => set('store_ip_hash', value)}
          label="Keep a hashed IP against visitors and sessions"
          hint="Off means nothing derived from an address is written at all."
        />
      </Card>

      <Card eyebrow="Consent" title="Ask before the widget records anything">
        <Toggle
          checked={draft.require_consent}
          onChange={(value) => set('require_consent', value)}
          label="Require consent before a visitor can chat"
          hint="With this on the widget makes no request until the visitor agrees — not even the page-view ping."
        />

        <div className="mt-4">
          <Field
            label="What they are asked to accept"
            hint="Left empty, a plain default is used. Keep it to a sentence; this is a gate, not a policy document."
          >
            {({ id, describedBy }) => (
              <Textarea
                id={id}
                rows={3}
                maxLength={500}
                aria-describedby={describedBy}
                disabled={!draft.require_consent}
                value={draft.consent_text ?? ''}
                onChange={(event) => set('consent_text', event.target.value)}
              />
            )}
          </Field>
        </div>
      </Card>

      <Card eyebrow="Uninstall" title="What happens when the plugin is removed">
        <p className="mb-4 text-xs leading-relaxed text-content-secondary">
          Off by default, and it stays off unless you change it here. Deactivating
          a plugin to debug a theme must never cost somebody their conversation
          history, so nothing is removed unless this was chosen deliberately and
          in advance.
        </p>

        <Toggle
          checked={draft.delete_on_uninstall}
          onChange={(value) => set('delete_on_uninstall', value)}
          label="Delete every table, option and cached value on uninstall"
          hint="Includes conversations, leads, the knowledge base and the encrypted provider keys. There is no undo and no export step."
          danger
        />
      </Card>

      <div className="flex items-center gap-3">
        <Button variant="primary" loading={save.isPending} onClick={submit}>
          Save privacy settings
        </Button>
        <span className="text-xs text-content-tertiary">
          Exports and erasures run from WordPress&rsquo;s own Tools &rsaquo; Export
          and Erase Personal Data.
        </span>
      </div>
    </div>
  );
}

interface ToggleProps {
  checked: boolean;
  onChange: (value: boolean) => void;
  label: string;
  hint: string;
  /** Ticking this one destroys data. Marked, not hidden. */
  danger?: boolean;
}

function Toggle({ checked, onChange, label, hint, danger = false }: ToggleProps) {
  return (
    <label className="flex cursor-pointer items-start gap-3">
      <input
        type="checkbox"
        checked={checked}
        onChange={(event) => onChange(event.target.checked)}
        className={cn(
          'mt-0.5 h-4 w-4 shrink-0 rounded border-border-strong accent-[var(--hvc-accent)]',
          'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent'
        )}
      />
      <span className="min-w-0">
        <span className="flex items-center gap-1.5 text-sm text-content">
          {label}
          {danger && checked && (
            <AlertTriangle
              size={12}
              aria-label="Destroys data"
              className="text-warning"
            />
          )}
        </span>
        <span className="mt-0.5 block text-xs leading-relaxed text-content-tertiary">
          {hint}
        </span>
      </span>
    </label>
  );
}
