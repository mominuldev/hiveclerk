import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

type Tone = 'neutral' | 'positive' | 'warning' | 'danger' | 'info';

const TONES: Record<Tone, string> = {
  neutral: 'border-border bg-surface-sunken text-content-secondary',
  positive: 'border-on-duty/25 bg-on-duty/10 text-on-duty',
  warning: 'border-warning/25 bg-warning/10 text-warning',
  danger: 'border-danger/25 bg-danger/10 text-danger',
  info: 'border-accent/25 bg-accent-subtle text-accent-text',
};

interface BadgeProps {
  tone?: Tone;
  icon?: ReactNode;
  children: ReactNode;
  className?: string;
}

/**
 * A small state label.
 *
 * Tone is always about state, never decoration — which is why the brand
 * gradient is not among the options. A badge that looked branded would
 * read as another status and mean nothing.
 */
export function Badge({
  tone = 'neutral',
  icon,
  children,
  className,
}: BadgeProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full border px-2 py-0.5',
        'text-[11px] font-medium leading-[18px] whitespace-nowrap',
        TONES[tone],
        className
      )}
    >
      {icon}
      {children}
    </span>
  );
}
