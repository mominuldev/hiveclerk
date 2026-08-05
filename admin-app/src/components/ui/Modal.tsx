import type { ReactNode } from 'react';
import {
  Dialog,
  DialogPanel,
  DialogTitle,
  Description,
} from '@headlessui/react';
import { X } from 'lucide-react';
import { cn } from '@/lib/cn';

interface ModalProps {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  children?: ReactNode;
  footer?: ReactNode;
  size?: 'sm' | 'md' | 'lg';
  /** Marks the primary action destructive; tints the header rule. */
  danger?: boolean;
}

const SIZES = {
  sm: 'max-w-sm',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
};

/**
 * A centred dialog for a decision.
 *
 * Built on Headless UI rather than hand-rolled: focus trapping, restoring
 * focus to the trigger on close, inert-ing the page behind, and Escape
 * handling are each easy to do badly and invisible when they are wrong.
 *
 * Reserved for things that need an answer before anything else continues.
 * Anything that is a task rather than a decision belongs in a Drawer,
 * where the context behind it stays readable.
 */
export function Modal({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  size = 'md',
  danger = false,
}: ModalProps) {
  return (
    <Dialog open={open} onClose={onClose} className="relative z-[100000]">
      {/* z-index clears the wp-admin menu (z-9990) and admin bar (99999).
          A dialog rendered under the admin bar is unclosable on mobile. */}
      <div
        className="fixed inset-0 bg-black/45 backdrop-blur-[2px]"
        aria-hidden="true"
      />

      <div className="fixed inset-0 flex items-center justify-center p-4">
        <DialogPanel
          className={cn(
            'w-full overflow-hidden rounded-xl border border-border bg-surface',
            'shadow-lg [box-shadow:var(--hvc-elevate),var(--hvc-shadow-lg)]',
            SIZES[size]
          )}
        >
          <span
            aria-hidden="true"
            className={cn(
              'block h-px w-full',
              danger ? 'bg-danger' : 'hvc-gradient-brand'
            )}
          />

          <header className="flex items-start justify-between gap-4 px-5 pb-3 pt-4">
            <div className="min-w-0">
              <DialogTitle className="font-display text-base font-bold tracking-[-0.01em] text-content">
                {title}
              </DialogTitle>
              {description && (
                <Description className="mt-1 text-sm leading-relaxed text-content-secondary">
                  {description}
                </Description>
              )}
            </div>

            <button
              type="button"
              onClick={onClose}
              aria-label="Close"
              className="-mr-1 -mt-1 rounded-lg p-1.5 text-content-tertiary transition-colors hover:bg-surface-hover hover:text-content"
            >
              <X size={16} />
            </button>
          </header>

          {children && <div className="px-5 pb-4">{children}</div>}

          {footer && (
            <footer className="flex items-center justify-end gap-2 border-t border-border bg-surface-sunken px-5 py-3">
              {footer}
            </footer>
          )}
        </DialogPanel>
      </div>
    </Dialog>
  );
}
