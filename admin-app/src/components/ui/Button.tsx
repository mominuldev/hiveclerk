import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'link';
type Size = 'sm' | 'md' | 'lg';

const VARIANTS: Record<Variant, string> = {
  // One primary action per screen. More than one means none.
  primary:
    'bg-accent text-on-accent hover:bg-accent-hover active:translate-y-px shadow-sm',
  secondary:
    'bg-surface text-content border border-border hover:bg-surface-hover hover:border-border-strong active:translate-y-px',
  ghost: 'text-content-secondary hover:bg-surface-hover hover:text-content',
  danger: 'bg-danger text-white hover:opacity-90 active:translate-y-px',
  link: 'text-accent-text underline-offset-4 hover:underline',
};

const SIZES: Record<Size, string> = {
  sm: 'h-7 px-2.5 text-xs gap-1.5',
  md: 'h-9 px-3.5 text-sm gap-2',
  lg: 'h-10 px-4 text-sm gap-2',
};

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  icon?: ReactNode;
}

export function Button({
  variant = 'secondary',
  size = 'md',
  loading = false,
  icon,
  disabled,
  className,
  children,
  ...rest
}: ButtonProps) {
  return (
    <button
      type="button"
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={cn(
        'inline-flex items-center justify-center rounded-lg font-medium',
        'transition-colors duration-[var(--hvc-duration-fast)]',
        'disabled:cursor-not-allowed disabled:opacity-45 disabled:active:translate-y-0',
        variant !== 'link' && SIZES[size],
        VARIANTS[variant],
        className
      )}
      {...rest}
    >
      {/* The spinner replaces the icon, never the label: a label that changes
          width mid-click makes the button jump under the cursor. */}
      {loading ? (
        <span
          aria-hidden="true"
          className="size-3.5 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent"
        />
      ) : (
        icon
      )}
      {children}
    </button>
  );
}
