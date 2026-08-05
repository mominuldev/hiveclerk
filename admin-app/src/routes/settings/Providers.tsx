import { CircleDollarSign, Info } from 'lucide-react';
import { Card, StatRow } from '@/components/ui/Card';
import { ErrorNotice } from '@/components/ui/ErrorNotice';
import { Skeleton } from '@/components/ui/Skeleton';
import { ProviderCard } from './ProviderCard';
import { useProviders, type ProviderState } from '@/api/queries/useProviders';
import { useCosts } from '@/api/queries/useCosts';
import { formatCompact, formatCost } from '@/lib/format';

/**
 * Model providers, and what they have cost.
 *
 * Spend sits beside the keys rather than only in Analytics because this is
 * the screen where the decision that drives it is made. Choosing a model
 * four times more expensive than the last one should not require going
 * somewhere else to find that out.
 */
export function Providers() {
  const { data, isPending, isError, error, refetch } = useProviders();

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />;
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
      <div className="space-y-3">
        {isPending
          ? [0, 1, 2, 3, 4].map((i) => (
              <Skeleton key={i} className="h-[58px] w-full rounded-xl" />
            ))
          : data.providers.map((provider, index) => (
              <ProviderCard
                key={provider.provider}
                provider={provider}
                defaultOpen={index === openIndex(data.providers)}
              />
            ))}

        {!isPending && (
          <p className="flex items-start gap-1.5 pt-1 text-xs leading-relaxed text-content-tertiary">
            <Info size={12} aria-hidden="true" className="mt-0.5 shrink-0" />
            Prices shown against models were last checked on {data.pricingAsOf}.
            They are estimates for planning, not an invoice — your provider's
            billing is authoritative.
          </p>
        )}
      </div>

      <SpendPanel />
    </div>
  );
}

/**
 * Which card, if any, starts open.
 *
 * On a fresh install every provider is unconfigured, and opening all five
 * gives the operator five identical key forms and no idea which to fill
 * in. One open card is a starting point; five is a wall. Once anything is
 * connected, nothing opens — the screen becomes a summary to scan rather
 * than a form to complete.
 */
function openIndex(providers: ProviderState[]): number {
  if (providers.some((provider) => provider.is_set)) {
    return -1;
  }

  return providers.findIndex((provider) => !provider.capabilities.needs_endpoint);
}

/**
 * Spend over the last thirty days.
 */
function SpendPanel() {
  const { data, isPending, isError } = useCosts();

  if (isError) {
    // A failure here must not take the settings screen down with it: the
    // operator came to fix a key, and spend is context, not the task.
    return null;
  }

  return (
    <Card
      eyebrow="Last 30 days"
      title="Provider spend"
      actions={
        <CircleDollarSign size={15} className="text-content-tertiary" aria-hidden="true" />
      }
      className="h-fit"
    >
      {isPending ? (
        <div className="space-y-2.5">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-8 w-full" />
          ))}
        </div>
      ) : (
        <>
          <dl>
            <StatRow label="Total" value={formatCost(data.total.cost)} emphasis />
            <StatRow label="Calls" value={data.total.calls.toLocaleString()} />
            <StatRow
              label="Tokens in"
              value={formatCompact(data.total.tokens_in)}
            />
            <StatRow
              label="Tokens out"
              value={formatCompact(data.total.tokens_out)}
            />
          </dl>

          {/* A total that silently omits calls it could not price looks
              identical to one that priced everything. Saying so is the
              difference between an estimate and a wrong number. */}
          {!data.total.complete && (
            <p className="mt-3 rounded-lg border border-border bg-surface-sunken px-3 py-2 text-xs leading-relaxed text-content-secondary">
              {data.total.unpriced.toLocaleString()}{' '}
              {data.total.unpriced === 1 ? 'call is' : 'calls are'} not included
              in this total — no published price is known for the model used.
            </p>
          )}

          {data.by_model.length > 0 && (
            <div className="mt-4">
              <p className="mb-2 text-[10px] font-semibold uppercase tracking-[0.12em] text-content-tertiary">
                By model
              </p>
              <dl>
                {data.by_model.slice(0, 5).map((slice) => (
                  <StatRow
                    key={`${slice.provider}:${slice.label}`}
                    label={slice.label}
                    value={formatCost(slice.cost)}
                  />
                ))}
              </dl>
            </div>
          )}

          {data.total.calls === 0 && (
            <p className="mt-3 text-xs leading-relaxed text-content-secondary">
              Nothing spent yet. Conversations start costing once a clerk goes
              on duty in Sprint 5.
            </p>
          )}
        </>
      )}
    </Card>
  );
}
