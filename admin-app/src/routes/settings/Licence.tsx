import { useState } from 'react';
import { RefreshCw, ShieldCheck, ShieldAlert, ShieldQuestion } from 'lucide-react';
import { Card, StatRow } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Field, Input } from '@/components/ui/Field';
import { toast } from '@/components/ui/Toast';
import { Modal } from '@/components/ui/Modal';
import {
  useActivateLicence,
  useDeactivateLicence,
  useLicence,
  useRecheckLicence,
} from '@/api/queries/useLicence';
import { formatTimestamp } from '@/lib/format';
import { cn } from '@/lib/cn';

const STATUS_ICON = {
  active: ShieldCheck,
  inactive: ShieldQuestion,
  expired: ShieldAlert,
  invalid: ShieldAlert,
  seat_limit: ShieldAlert,
  unreachable: ShieldQuestion,
} as const;

const STATUS_COLOUR = {
  active: 'text-[var(--hvc-on-duty)]',
  inactive: 'text-content-tertiary',
  expired: 'text-[var(--hvc-warning)]',
  invalid: 'text-[var(--hvc-danger)]',
  seat_limit: 'text-[var(--hvc-warning)]',
  unreachable: 'text-content-tertiary',
} as const;

const FEATURE_LABELS: Record<string, string> = {
  crm: 'CRM sync',
  email_sequences: 'Email sequences',
  remove_badge: 'Removing the widget badge',
  white_label: 'White-label mode',
  multisite: 'Multisite',
};

/**
 * The licence tab (FR-SYS-01).
 *
 * Shows what is in force rather than what was bought, because those
 * differ the moment a licence lapses — and a screen reading "Pro" above a
 * CRM page that refuses to connect is a support ticket.
 *
 * Deactivating is behind a confirmation because it releases this site's
 * seat on the customer's licence server, and that is not obviously
 * reversible from inside this screen.
 */
export function LicenceSettings() {
  const [key, setKey] = useState('');
  const [confirming, setConfirming] = useState(false);

  const { data: licence } = useLicence();
  const activate = useActivateLicence();
  const deactivate = useDeactivateLicence();
  const recheck = useRecheckLicence();

  if (!licence) {
    return null;
  }

  const Icon = STATUS_ICON[licence.status];
  const lapsed = licence.tier !== licence.effective_tier;

  const submit = () => {
    activate.mutate(key.trim(), {
      onSuccess: (result) => {
        setKey('');

        if ('active' === result.status) {
          toast.success(`${result.tier_label} licence active on this site.`);
          return;
        }

        toast.error(result.status_label, result.guidance ?? undefined);
      },
      onError: (error) => toast.error(error.message),
    });
  };

  return (
    <div className="space-y-5">
      <Card
        eyebrow="Licence"
        title={licence.is_set ? `${licence.tier_label}` : 'No licence on this site'}
        actions={
          <span
            className={cn(
              'inline-flex items-center gap-1.5 text-xs font-medium',
              STATUS_COLOUR[licence.status]
            )}
          >
            <Icon size={14} aria-hidden="true" />
            {licence.status_label}
          </span>
        }
      >
        {licence.guidance && (
          <p className="mb-4 rounded-lg border border-border bg-surface-sunken px-3 py-2 text-sm leading-relaxed text-content-secondary">
            {licence.guidance}
          </p>
        )}

        {lapsed && (
          <p className="mb-4 rounded-lg border border-border bg-surface-sunken px-3 py-2 text-sm leading-relaxed text-content">
            Your clerks are still answering and nothing has been deleted. While
            the licence is not active this site runs on free-tier limits.
          </p>
        )}

        {licence.is_set ? (
          <>
            <dl>
              <StatRow label="Key" value={licence.masked ?? '—'} emphasis />
              {licence.customer && (
                <StatRow label="Account" value={licence.customer} />
              )}
              <StatRow
                label="Sites in use"
                value={`${licence.sites} of ${licence.site_limit}`}
              />
              <StatRow
                label="Renews"
                value={
                  licence.expires_at
                    ? `${formatTimestamp(licence.expires_at)}${
                        licence.days_remaining !== null && licence.days_remaining >= 0
                          ? ` · ${licence.days_remaining} days`
                          : ''
                      }`
                    : 'No expiry'
                }
              />
              <StatRow
                label="Last checked"
                value={
                  licence.checked_at ? formatTimestamp(licence.checked_at) : 'Never'
                }
              />
            </dl>

            <div className="mt-4 flex items-center gap-2">
              <Button
                size="sm"
                loading={recheck.isPending}
                icon={<RefreshCw size={13} aria-hidden="true" />}
                onClick={() =>
                  recheck.mutate(undefined, {
                    onSuccess: (result) => toast.info(result.status_label),
                    onError: (error) => toast.error(error.message),
                  })
                }
              >
                Check again
              </Button>
              <Button
                size="sm"
                variant="ghost"
                onClick={() => setConfirming(true)}
              >
                Remove from this site
              </Button>
            </div>
          </>
        ) : (
          <div className="max-w-md space-y-3">
            <Field
              label="Licence key"
              hint="From your purchase receipt. It is stored encrypted and never sent back to this screen."
            >
              {({ id, describedBy }) => (
                <Input
                  id={id}
                  mono
                  aria-describedby={describedBy}
                  value={key}
                  autoComplete="off"
                  spellCheck={false}
                  placeholder="HVC-XXXXXXXX-XXXXXXXX"
                  onChange={(event) => setKey(event.target.value)}
                  onKeyDown={(event) => {
                    if ('Enter' === event.key) {
                      submit();
                    }
                  }}
                />
              )}
            </Field>

            <Button
              variant="primary"
              loading={activate.isPending}
              disabled={key.trim().length < 16}
              onClick={submit}
            >
              Activate
            </Button>
          </div>
        )}
      </Card>

      <Card eyebrow="What this licence covers" title="Limits and features">
        <dl>
          <StatRow
            label="Clerks"
            value={
              licence.limits.clerks === null
                ? 'Unlimited'
                : licence.limits.clerks.toLocaleString()
            }
            emphasis
          />
          <StatRow
            label="Indexed chunks"
            value={
              licence.limits.chunks === null
                ? 'Unlimited'
                : licence.limits.chunks.toLocaleString()
            }
          />
          {Object.entries(licence.features).map(([feature, included]) => (
            <StatRow
              key={feature}
              label={FEATURE_LABELS[feature] ?? feature}
              value={included ? 'Included' : 'Not on this plan'}
            />
          ))}
        </dl>

        <p className="mt-4 text-xs leading-relaxed text-content-tertiary">
          Reaching a limit never removes anything you have already made.
          Everything indexed stays searchable, every clerk keeps answering, and
          every lead stays where it is.
        </p>
      </Card>

      <Modal
        open={confirming}
        onClose={() => setConfirming(false)}
        title="Remove this licence?"
      >
        <p className="text-sm leading-relaxed text-content-secondary">
          This releases the seat so you can use the key on another site. Your
          clerks, knowledge and leads are untouched — this site drops to
          free-tier limits until a licence is activated again.
        </p>

        <div className="mt-5 flex justify-end gap-2">
          <Button onClick={() => setConfirming(false)}>Keep it</Button>
          <Button
            variant="danger"
            loading={deactivate.isPending}
            onClick={() =>
              deactivate.mutate(undefined, {
                onSuccess: () => {
                  setConfirming(false);
                  toast.info('Licence removed from this site.');
                },
                onError: (error) => toast.error(error.message),
              })
            }
          >
            Remove it
          </Button>
        </div>
      </Modal>
    </div>
  );
}
