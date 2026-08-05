import { useEffect, useState } from 'react';
import { Lock } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Field, Input } from '@/components/ui/Field';
import { Skeleton } from '@/components/ui/Skeleton';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { toast } from '@/components/ui/Toast';
import {
  useBranding,
  useSaveBranding,
  type BrandingSettings,
} from '@/api/queries/useBranding';
import { cn } from '@/lib/cn';

/**
 * White-label settings (FR-SYS-08).
 *
 * Saving is never refused on tier. The server keeps what was chosen and
 * reports what is in force, so an agency-in-waiting can configure
 * everything now and have it take effect the moment they upgrade — rather
 * than re-ticking every box afterwards.
 *
 * The screen's job is to make the difference visible. A locked control
 * that still accepts input and says why is more honest than a disabled
 * one that says nothing.
 */
export function Branding() {
  const { data, isPending, isError, error, refetch } = useBranding();
  const save = useSaveBranding();
  const [draft, setDraft] = useState<BrandingSettings | null>(null);

  useEffect(() => {
    if (data && null === draft) {
      setDraft(data.settings);
    }
  }, [data, draft]);

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  if (isPending || !data || !draft) {
    return <Skeleton className="h-[420px] w-full rounded-xl" />;
  }

  const set = <K extends keyof BrandingSettings>(
    key: K,
    value: BrandingSettings[K]
  ) => setDraft({ ...draft, [key]: value });

  const submit = () => {
    save.mutate(draft, {
      onSuccess: (state) => {
        setDraft(state.settings);
        toast.success('Branding saved.');
      },
      onError: (mutationError) => toast.error(mutationError.message),
    });
  };

  return (
    <div className="space-y-5">
      <Card eyebrow="Widget" title="The badge visitors see">
        <Toggle
          checked={draft.hide_badge}
          onChange={(value) => set('hide_badge', value)}
          label="Hide the badge on the chat widget"
          hint={
            data.entitlements.remove_badge
              ? 'The widget carries no attribution.'
              : 'Included from Pro. You can set it now; it takes effect when a licence covers it.'
          }
          locked={!data.entitlements.remove_badge}
        />

        <p className="mt-4 rounded-lg border border-border bg-surface-sunken px-3 py-2 text-xs leading-relaxed text-content-secondary">
          Right now the widget{' '}
          {data.effective.showBadge ? (
            <>
              shows <strong className="font-medium text-content">the badge</strong>.
            </>
          ) : (
            <>carries <strong className="font-medium text-content">no badge</strong>.</>
          )}
        </p>
      </Card>

      <Card eyebrow="Admin" title="White-label mode">
        <Toggle
          checked={draft.white_label}
          onChange={(value) => set('white_label', value)}
          label="Replace the product name and mark throughout the admin"
          hint={
            data.entitlements.white_label
              ? 'Your clients see your name, not ours.'
              : 'Included with Agency. You can set it now; it takes effect when a licence covers it.'
          }
          locked={!data.entitlements.white_label}
        />

        <div className="mt-5 grid gap-4 sm:grid-cols-2">
          <Field
            label="Product name"
            hint="Shown in the sidebar, the WordPress menu and the browser tab."
          >
            {({ id, describedBy }) => (
              <Input
                id={id}
                aria-describedby={describedBy}
                value={draft.product_name}
                maxLength={40}
                onChange={(event) => set('product_name', event.target.value)}
              />
            )}
          </Field>

          <Field label="Logo URL" hint="A square image, at least 96 pixels.">
            {({ id, describedBy }) => (
              <Input
                id={id}
                type="url"
                aria-describedby={describedBy}
                value={draft.logo_url}
                placeholder="https://…"
                onChange={(event) => set('logo_url', event.target.value)}
              />
            )}
          </Field>

          <Field label="Accent colour" hint="A six-digit hex value.">
            {({ id, describedBy }) => (
              <Input
                id={id}
                mono
                aria-describedby={describedBy}
                value={draft.accent}
                placeholder="#3b5bdb"
                onChange={(event) => set('accent', event.target.value)}
              />
            )}
          </Field>

          <Field
            label="Support URL"
            hint="Where help links in the admin go. Yours, not ours."
          >
            {({ id, describedBy }) => (
              <Input
                id={id}
                type="url"
                aria-describedby={describedBy}
                value={draft.support_url}
                placeholder="https://…"
                onChange={(event) => set('support_url', event.target.value)}
              />
            )}
          </Field>

          <Field
            label="Badge text"
            hint="Replaces “Powered by Hiveclerk” when the badge is shown."
          >
            {({ id, describedBy }) => (
              <Input
                id={id}
                aria-describedby={describedBy}
                value={draft.badge_label}
                maxLength={40}
                placeholder="Powered by Acme"
                onChange={(event) => set('badge_label', event.target.value)}
              />
            )}
          </Field>

          <Field label="Badge link" hint="Where the badge sends a visitor.">
            {({ id, describedBy }) => (
              <Input
                id={id}
                type="url"
                aria-describedby={describedBy}
                value={draft.badge_url}
                placeholder="https://…"
                onChange={(event) => set('badge_url', event.target.value)}
              />
            )}
          </Field>
        </div>

        <div className="mt-5 flex items-center gap-3">
          <Button variant="primary" loading={save.isPending} onClick={submit}>
            Save branding
          </Button>
          <span className="text-xs text-content-tertiary">
            In force now:{' '}
            <span className="text-content-secondary">
              {data.effective.productName}
            </span>
          </span>
        </div>
      </Card>
    </div>
  );
}

interface ToggleProps {
  checked: boolean;
  onChange: (value: boolean) => void;
  label: string;
  hint: string;
  /** The tier does not cover this. The control still works; the effect does not. */
  locked: boolean;
}

function Toggle({ checked, onChange, label, hint, locked }: ToggleProps) {
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
          {locked && (
            <Lock
              size={12}
              aria-label="Not on this plan yet"
              className="text-content-tertiary"
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
