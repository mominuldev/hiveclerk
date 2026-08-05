import type { ReactNode } from 'react';
import { Logomark } from '@/components/brand/Logomark';

interface EmptyStateProps {
  title: string;
  description?: string;
  action?: ReactNode;
  /** Hide the mark on nested or repeated empty states. */
  bare?: boolean;
}

/**
 * An empty screen is an invitation to act, not a report that nothing exists.
 */
export function EmptyState({
  title,
  description,
  action,
  bare = false,
}: EmptyStateProps) {
  return (
    <div className="flex flex-col items-center justify-center px-6 py-14 text-center">
      {!bare && (
        <div className="relative mb-5">
          <span
            aria-hidden="true"
            className="absolute -inset-6 rounded-full opacity-40 blur-2xl"
            style={{ backgroundImage: 'var(--hvc-gradient-brand-soft)' }}
          />
          <Logomark size={40} className="relative rounded-xl" />
        </div>
      )}

      <h3 className="font-display text-base font-bold tracking-[-0.01em] text-content">
        {title}
      </h3>

      {description && (
        <p className="mt-2 max-w-[42ch] text-sm leading-relaxed text-content-secondary">
          {description}
        </p>
      )}

      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}
