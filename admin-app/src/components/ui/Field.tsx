import type {
  InputHTMLAttributes,
  ReactNode,
  SelectHTMLAttributes,
} from 'react';
import { useId } from 'react';
import { AlertCircle } from 'lucide-react';
import { cn } from '@/lib/cn';

interface FieldProps {
  label: string;
  /** Shown under the label. Explains the field; never repeats it. */
  hint?: string;
  error?: string;
  /** Rendered on the right of the label row — a link to a provider console. */
  aside?: ReactNode;
  children: (props: { id: string; describedBy?: string }) => ReactNode;
}

/**
 * Label, hint and error wrapper.
 *
 * Takes a render prop rather than cloning its child so the ids it
 * generates are wired to the control explicitly. Cloning works until
 * someone wraps the input in a div, at which point the label silently
 * stops pointing at anything and only a screen reader user finds out.
 */
export function Field({ label, hint, error, aside, children }: FieldProps) {
  const id = useId();
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;

  const describedBy =
    [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') ||
    undefined;

  return (
    <div className="space-y-1.5">
      <div className="flex items-baseline justify-between gap-3">
        <label htmlFor={id} className="text-sm font-medium text-content">
          {label}
        </label>
        {aside}
      </div>

      {hint && (
        <p id={hintId} className="text-xs leading-relaxed text-content-tertiary">
          {hint}
        </p>
      )}

      {children({ id, ...(describedBy ? { describedBy } : {}) })}

      {error && (
        <p
          id={errorId}
          className="flex items-start gap-1.5 text-xs text-danger"
          role="alert"
        >
          <AlertCircle size={13} className="mt-px shrink-0" />
          {error}
        </p>
      )}
    </div>
  );
}

const CONTROL = [
  'w-full rounded-lg border border-border bg-surface-sunken px-3 text-sm text-content',
  'placeholder:text-content-tertiary',
  'transition-colors duration-[var(--hvc-duration-fast)]',
  'hover:border-border-strong',
  'focus:border-accent focus:outline-none',
  'disabled:cursor-not-allowed disabled:opacity-55',
].join(' ');

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  invalid?: boolean;
  /** Renders a monospace face — for keys, ids and endpoints. */
  mono?: boolean;
}

export function Input({ invalid, mono, className, ...rest }: InputProps) {
  return (
    <input
      className={cn(
        CONTROL,
        'h-9',
        mono && 'font-mono text-[13px]',
        invalid && 'border-danger',
        className
      )}
      aria-invalid={invalid || undefined}
      {...rest}
    />
  );
}

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  invalid?: boolean;
}

/**
 * A native select.
 *
 * Deliberately not a custom listbox. Model lists run to hundreds of
 * entries on OpenRouter, and the browser's own control handles type-ahead,
 * long lists and small screens better than anything reimplemented here.
 */
export function Select({ invalid, className, children, ...rest }: SelectProps) {
  return (
    <select
      className={cn(
        CONTROL,
        'h-9 cursor-pointer appearance-none pr-8',
        // The caret comes from a CSS class, not a bg-[url()] utility.
        // See the note in tailwind.css: mixing the two makes
        // tailwind-merge drop the background colour.
        'hvc-select-caret',
        invalid && 'border-danger',
        className
      )}
      aria-invalid={invalid || undefined}
      {...rest}
    >
      {children}
    </select>
  );
}
