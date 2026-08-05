import type { ReactNode } from 'react';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { X } from 'lucide-react';
import { cn } from '@/lib/cn';

interface DrawerProps {
  open: boolean;
  onClose: () => void;
  title: string;
  subtitle?: string;
  children: ReactNode;
  footer?: ReactNode;
  width?: 'md' | 'lg';
}

const WIDTHS = {
  md: 'max-w-md',
  lg: 'max-w-2xl',
};

/**
 * A side panel for detail and editing.
 *
 * The right choice wherever the list behind it is still the point: an
 * audit entry's payload, a conversation transcript, a source's chunks.
 * Keeping the list visible means the operator does not lose their place,
 * which a full-page route or a centred modal both cost them.
 *
 * The panel scrolls; the header and footer do not. A save button that
 * scrolls out of reach is the most common way this pattern gets it wrong.
 */
export function Drawer({
  open,
  onClose,
  title,
  subtitle,
  children,
  footer,
  width = 'md',
}: DrawerProps) {
  return (
    <Dialog open={open} onClose={onClose} className="relative z-[100000]">
      <div
        className="fixed inset-0 bg-black/40 backdrop-blur-[2px]"
        aria-hidden="true"
      />

      <div className="fixed inset-y-0 right-0 flex max-w-full pl-10">
        <DialogPanel
          className={cn(
            'flex h-full w-screen flex-col border-l border-border bg-surface shadow-lg',
            WIDTHS[width]
          )}
        >
          <header className="flex shrink-0 items-start justify-between gap-4 border-b border-border px-5 py-4">
            <div className="min-w-0">
              <DialogTitle className="font-display text-base font-bold tracking-[-0.01em] text-content">
                {title}
              </DialogTitle>
              {subtitle && (
                <p className="mt-0.5 truncate text-xs text-content-tertiary">
                  {subtitle}
                </p>
              )}
            </div>

            <button
              type="button"
              onClick={onClose}
              aria-label="Close"
              className="-mr-1 rounded-lg p-1.5 text-content-tertiary transition-colors hover:bg-surface-hover hover:text-content"
            >
              <X size={16} />
            </button>
          </header>

          <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
            {children}
          </div>

          {footer && (
            <footer className="flex shrink-0 items-center justify-end gap-2 border-t border-border bg-surface-sunken px-5 py-3">
              {footer}
            </footer>
          )}
        </DialogPanel>
      </div>
    </Dialog>
  );
}
