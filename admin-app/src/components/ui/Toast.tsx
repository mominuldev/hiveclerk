import { useEffect } from 'react';
import { create } from 'zustand';
import { AlertTriangle, Check, Info, X } from 'lucide-react';
import { cn } from '@/lib/cn';

type ToastTone = 'success' | 'error' | 'info';

interface Toast {
  id: number;
  tone: ToastTone;
  message: string;
  /** Extra line under the message. Used for a provider's own wording. */
  detail?: string;
}

interface ToastStore {
  toasts: Toast[];
  push: (toast: Omit<Toast, 'id'>) => void;
  dismiss: (id: number) => void;
}

let nextId = 1;

const useToastStore = create<ToastStore>((set) => ({
  toasts: [],
  push: (toast) =>
    set((state) => ({ toasts: [...state.toasts, { ...toast, id: nextId++ }] })),
  dismiss: (id) =>
    set((state) => ({ toasts: state.toasts.filter((t) => t.id !== id) })),
}));

/**
 * Raise a toast from anywhere, including outside a component.
 *
 * Mutations fire from query callbacks that have no hook context, so this
 * reads the store directly rather than being a hook.
 */
export const toast = {
  success: (message: string, detail?: string) =>
    useToastStore.getState().push({
      tone: 'success',
      message,
      ...(detail ? { detail } : {}),
    }),
  error: (message: string, detail?: string) =>
    useToastStore.getState().push({
      tone: 'error',
      message,
      ...(detail ? { detail } : {}),
    }),
  info: (message: string, detail?: string) =>
    useToastStore.getState().push({
      tone: 'info',
      message,
      ...(detail ? { detail } : {}),
    }),
};

const TONES: Record<ToastTone, { icon: typeof Check; className: string }> = {
  success: { icon: Check, className: 'text-on-duty' },
  error: { icon: AlertTriangle, className: 'text-danger' },
  info: { icon: Info, className: 'text-accent' },
};

/**
 * Where toasts appear. Mounted once, in the app shell.
 *
 * Uses `role="status"` on the region rather than `alert` on each toast:
 * alert interrupts whatever a screen reader is saying, which is right for
 * a failure and rude for "Saved". Errors stay on screen until dismissed
 * for the same reason — a message that disappears before it is read is the
 * same as no message.
 */
export function ToastViewport() {
  const toasts = useToastStore((state) => state.toasts);
  const dismiss = useToastStore((state) => state.dismiss);

  return (
    <div
      role="status"
      aria-live="polite"
      className="pointer-events-none fixed bottom-4 right-4 z-[100001] flex w-full max-w-sm flex-col gap-2"
    >
      {toasts.map((item) => (
        <ToastRow key={item.id} toast={item} onDismiss={dismiss} />
      ))}
    </div>
  );
}

function ToastRow({
  toast: item,
  onDismiss,
}: {
  toast: Toast;
  onDismiss: (id: number) => void;
}) {
  const { icon: Icon, className } = TONES[item.tone];

  useEffect(() => {
    // Failures need reading and often need acting on, so only the
    // non-failures expire on their own.
    if (item.tone === 'error') {
      return;
    }

    const timer = window.setTimeout(() => onDismiss(item.id), 4000);

    return () => window.clearTimeout(timer);
  }, [item.id, item.tone, onDismiss]);

  return (
    <div
      className={cn(
        'pointer-events-auto flex items-start gap-2.5 rounded-xl border border-border',
        'bg-surface px-3.5 py-3 shadow-lg [box-shadow:var(--hvc-elevate),var(--hvc-shadow-lg)]'
      )}
    >
      <Icon size={15} className={cn('mt-px shrink-0', className)} />

      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium text-content">{item.message}</p>
        {item.detail && (
          <p className="mt-0.5 text-xs leading-relaxed text-content-secondary">
            {item.detail}
          </p>
        )}
      </div>

      <button
        type="button"
        onClick={() => onDismiss(item.id)}
        aria-label="Dismiss"
        className="-mr-1 -mt-0.5 rounded-md p-1 text-content-tertiary transition-colors hover:bg-surface-hover hover:text-content"
      >
        <X size={13} />
      </button>
    </div>
  );
}
