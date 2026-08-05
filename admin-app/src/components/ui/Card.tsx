import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface CardProps {
  title?: string;
  eyebrow?: string;
  actions?: ReactNode;
  children?: ReactNode;
  className?: string;
  /** Adds the brand wash. Reserved for the one hero card on a screen. */
  feature?: boolean;
}

export function Card({
  title,
  eyebrow,
  actions,
  children,
  className,
  feature = false,
}: CardProps) {
  return (
    <section
      className={cn(
        'relative overflow-hidden rounded-xl border border-border bg-surface p-5',
        'shadow-sm [box-shadow:var(--hvc-elevate),var(--hvc-shadow-sm)]',
        className
      )}
    >
      {feature && (
        <span
          aria-hidden="true"
          className="pointer-events-none absolute inset-x-0 top-0 h-px"
          style={{ backgroundImage: 'var(--hvc-gradient-brand)' }}
        />
      )}

      {(title || eyebrow || actions) && (
        <header className="mb-4 flex items-start justify-between gap-4">
          <div className="min-w-0">
            {eyebrow && (
              <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-content-tertiary">
                {eyebrow}
              </p>
            )}
            {title && (
              <h2 className="font-display text-base font-bold tracking-[-0.01em] text-content">
                {title}
              </h2>
            )}
          </div>
          {actions && (
            <div className="flex shrink-0 items-center gap-2">{actions}</div>
          )}
        </header>
      )}

      {children}
    </section>
  );
}

interface StatRowProps {
  label: string;
  value: string;
  emphasis?: boolean;
}

/**
 * A label/value pair with tabular figures so columns line up.
 */
export function StatRow({ label, value, emphasis = false }: StatRowProps) {
  return (
    <div className="flex items-baseline justify-between gap-4 border-b border-border py-2 last:border-0">
      <dt className="text-sm text-content-tertiary">{label}</dt>
      <dd
        className={cn(
          'font-mono text-[13px] tabular-nums',
          emphasis ? 'text-content' : 'text-content-secondary'
        )}
      >
        {value}
      </dd>
    </div>
  );
}
