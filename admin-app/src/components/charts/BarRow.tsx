import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface BarRowProps {
  label: ReactNode;
  value: string;
  /** Share of the widest bar, 0 to 1. */
  fraction: number;
  /** Second line under the label. */
  detail?: ReactNode;
  /** Uses the brand gradient instead of the flat accent. */
  feature?: boolean;
  className?: string;
}

/**
 * One horizontal bar with its figure, used by the funnel and by-clerk panels.
 *
 * The bar is scaled against the largest value in its group rather than
 * against 100%. A funnel whose top rung fills the panel and whose bottom
 * rung is a sliver is the shape of the problem — normalising each row to
 * its own maximum would draw five full bars and say nothing.
 *
 * The figure is always rendered as text beside the bar. A length is a
 * comparison, not a measurement, and the number is what somebody quotes.
 */
export function BarRow({
  label,
  value,
  fraction,
  detail,
  feature = false,
  className,
}: BarRowProps) {
  const width = Math.max(0, Math.min(1, Number.isFinite(fraction) ? fraction : 0));

  return (
    <div className={cn('py-2', className)}>
      <div className="mb-1.5 flex items-baseline justify-between gap-4">
        <span className="min-w-0 truncate text-sm text-content">{label}</span>
        <span className="shrink-0 font-mono text-[13px] tabular-nums text-content">
          {value}
        </span>
      </div>

      <div className="h-1.5 w-full overflow-hidden rounded-full bg-surface-sunken">
        <div
          className={cn(
            'h-full rounded-full transition-[width] duration-[var(--hvc-duration-slow)] motion-reduce:transition-none',
            feature ? 'hvc-gradient-brand' : 'bg-accent'
          )}
          style={{ width: `${(width * 100).toFixed(1)}%` }}
        />
      </div>

      {detail && (
        <p className="mt-1.5 text-xs leading-relaxed text-content-tertiary">
          {detail}
        </p>
      )}
    </div>
  );
}
