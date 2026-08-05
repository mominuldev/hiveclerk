import { Component, type ErrorInfo, type ReactNode } from 'react';
import { AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/Button';

interface Props {
  children: ReactNode;
  /**
   * Changing this resets the boundary. The router location is passed in,
   * so navigating away from a screen that threw gives the operator a
   * working admin again without a reload.
   */
  resetKey?: string;
}

interface State {
  error: Error | null;
}

/**
 * Keeps one broken panel from taking the whole admin down.
 *
 * Sprint 9 shipped a status badge that read a property off `undefined`
 * when a payload carried a lifecycle value where a duty value was
 * expected. React's default response to a throw during render is to
 * unmount the entire tree, so the symptom was a white screen on every
 * route rather than a broken badge on one — and because the screen was
 * blank, it read as a failed request rather than as a render error. The
 * endpoint was healthy throughout.
 *
 * Typing fixed that instance. This exists because typing cannot fix the
 * next one: every payload crossing the REST boundary is only as true as
 * its declaration, and one wrong `as` is all it takes.
 *
 * A class component, which is not the house style anywhere else in this
 * app. React offers no hook equivalent — `componentDidCatch` is the only
 * way to catch a render error, and a third-party package to avoid one
 * class is a dependency bought for style.
 */
export class ErrorBoundary extends Component<Props, State> {
  override state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  override componentDidUpdate(previous: Props): void {
    if (this.state.error && previous.resetKey !== this.props.resetKey) {
      this.setState({ error: null });
    }
  }

  override componentDidCatch(error: Error, info: ErrorInfo): void {
    /*
     * The console is the only sink available. There is no error-reporting
     * endpoint and there should not be one: this plugin's whole promise
     * is that the customer's data stays on their server, and a render
     * error carries whatever was being rendered — a lead's name, a
     * visitor's question — straight off it.
     */
    console.error('Hiveclerk: a screen failed to render.', error, info.componentStack);
  }

  override render(): ReactNode {
    const { error } = this.state;

    if (!error) {
      return this.props.children;
    }

    return (
      <div
        role="alert"
        className="rounded-xl border border-warning/40 bg-warning/10 p-6"
      >
        <div className="flex items-start gap-3">
          <AlertTriangle size={18} aria-hidden="true" className="mt-0.5 shrink-0" />

          <div className="min-w-0 space-y-2">
            <h2 className="text-sm font-medium text-content">
              This screen could not be drawn
            </h2>

            <p className="max-w-prose text-sm leading-relaxed text-content-secondary">
              Everything else still works — use the sidebar to carry on. Your data
              is not affected; this is a display fault, and nothing was being
              saved.
            </p>

            {/*
              Shown, not hidden behind a toggle. The person reading this is
              an administrator on their own site, and the message is the
              one thing that makes the difference between a support ticket
              that can be answered and one that says "it went blank".
            */}
            <p className="max-w-prose overflow-x-auto rounded-lg border border-border bg-surface px-3 py-2 font-mono text-xs text-content-secondary">
              {error.message || error.name}
            </p>

            <div className="flex flex-wrap items-center gap-2 pt-1">
              <Button
                variant="secondary"
                onClick={() => this.setState({ error: null })}
              >
                Try again
              </Button>
              <Button variant="ghost" onClick={() => window.location.reload()}>
                Reload the page
              </Button>
            </div>
          </div>
        </div>
      </div>
    );
  }
}
