import { ArrowDown, ArrowUp } from 'lucide-react';
import { Sparkline } from '@/components/charts/Sparkline';
import type { Kpi } from '@/api/queries/useAnalytics';
import { formatCost } from '@/lib/format';
import { cn } from '@/lib/cn';

interface KpiCardProps {
  kpi: Kpi;
  /** Marks the North Star card. Exactly one per row. */
  feature?: boolean;
}

function formatValue(kpi: Kpi): string {
  if (kpi.format === 'currency') {
    return formatCost(kpi.value);
  }

  if (kpi.format === 'percent') {
    return `${(kpi.value * 100).toFixed(1)}%`;
  }

  return Math.round(kpi.value).toLocaleString();
}

export function KpiCard({ kpi, feature = false }: KpiCardProps) {
  const change = kpi.change;
  const rising = change !== null && change > 0;
  // Direction and judgement are different questions. Spend rising is an
  // arrow up and bad news, and a card that coloured every increase green
  // would say the opposite of what it means.
  const good = change === null || change === 0 ? null : rising === kpi.higher_is_better;

  return (
    <div className="relative overflow-hidden rounded-xl border border-border bg-surface p-4 [box-shadow:var(--hvc-elevate),var(--hvc-shadow-sm)]">
      {feature && (
        <span
          aria-hidden="true"
          className="pointer-events-none absolute inset-x-0 top-0 h-px"
          style={{ backgroundImage: 'var(--hvc-gradient-brand)' }}
        />
      )}

      <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-content-tertiary">
        {kpi.label}
      </p>

      <p className="mt-2 font-display text-2xl font-bold tabular-nums tracking-[-0.02em] text-content">
        {formatValue(kpi)}
      </p>

      <div className="mt-1 flex h-4 items-center gap-1 text-xs">
        {change === null ? (
          // No comparison rather than "0%". The previous period had
          // nothing in it, and a percentage against zero is either
          // infinity or a lie.
          <span className="text-content-tertiary">No earlier period</span>
        ) : (
          <span
            className={cn(
              'inline-flex items-center gap-0.5 font-medium tabular-nums',
              good === null && 'text-content-tertiary',
              good === true && 'text-[var(--hvc-on-duty-ink)]',
              good === false && 'text-[var(--hvc-danger-ink)]'
            )}
          >
            {rising ? <ArrowUp size={12} aria-hidden="true" /> : <ArrowDown size={12} aria-hidden="true" />}
            {Math.abs(change * 100).toFixed(1)}%
            <span className="sr-only">
              {rising ? 'up' : 'down'} against the previous period
            </span>
          </span>
        )}
      </div>

      <div
        className={cn(
          'mt-3',
          good === false ? 'text-[var(--hvc-danger-ink)]' : 'text-accent'
        )}
      >
        <Sparkline values={kpi.series} label={kpi.label} />
      </div>
    </div>
  );
}
