import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

type Tone = 'neutral' | 'positive' | 'warning' | 'danger' | 'info';

/*
 * Border and fill take the state colour; the label takes its ink variant.
 *
 * A badge is the worst case for contrast in the whole admin — small text on
 * a 10% tint of the very colour it is written in. `text-warning` on
 * `bg-warning/10` measured 2.85:1 against the 4.5:1 AA needs. The ink
 * tokens are the same hues a step darker, and only in the light theme,
 * where the tint is pale enough for it to matter.
 */
const TONES: Record<Tone, string> = {
  neutral: 'border-border bg-surface-sunken text-content-secondary',
  positive: 'border-on-duty/25 bg-on-duty/10 text-on-duty-ink',
  warning: 'border-warning/25 bg-warning/10 text-warning-ink',
  danger: 'border-danger/25 bg-danger/10 text-danger-ink',
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
