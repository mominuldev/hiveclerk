import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ErrorBoundary } from '@/components/ErrorBoundary';

/**
 * The component that exists because a white screen shipped.
 *
 * Sprint 9 shipped a status badge that read a property off `undefined`.
 * React's response to a throw during render is to unmount the entire
 * tree, so the symptom was a blank admin on every route rather than a
 * broken badge on one — and because it was blank, it read as a failed
 * request rather than a render error. The endpoint was healthy the whole
 * time.
 *
 * `tools/boundary-probe.mjs` reproduces that against the built bundle in
 * a real browser, which is the right check for "does the shipped artefact
 * survive it". It is also slow, needs Playwright and a running site, and
 * runs nowhere near a developer's edit loop. These are the same
 * behaviours where they cost nothing.
 */

function Boom(): never {
  throw new Error('a payload carried a shape the component did not expect');
}

describe('ErrorBoundary', () => {
  /*
   * React logs caught render errors to console.error regardless of the
   * boundary, and the boundary logs deliberately as well. Silenced per
   * test rather than globally: a test that does not expect an error
   * should still be noisy about one.
   */
  function silenceReactErrorLogging() {
    return vi.spyOn(console, 'error').mockImplementation(() => {});
  }

  it('renders its children when nothing throws', () => {
    render(
      <ErrorBoundary>
        <p>the screen</p>
      </ErrorBoundary>
    );

    expect(screen.getByText('the screen')).toBeInTheDocument();
  });

  it('shows a failure notice instead of unmounting the tree', () => {
    const logged = silenceReactErrorLogging();

    render(
      <ErrorBoundary>
        <Boom />
      </ErrorBoundary>
    );

    // Something is on screen. The precise wording is the product's to
    // change; that the operator is not looking at nothing is not.
    expect(document.body.textContent).not.toBe('');
    expect(logged).toHaveBeenCalled();
  });

  it('keeps everything outside the boundary alive', () => {
    silenceReactErrorLogging();

    render(
      <div>
        <nav>the sidebar</nav>
        <ErrorBoundary>
          <Boom />
        </ErrorBoundary>
      </div>
    );

    // This is the whole point. A broken panel leaves the operator one
    // click from a working screen instead of staring at a blank page.
    expect(screen.getByText('the sidebar')).toBeInTheDocument();
  });

  it('recovers when the reset key changes, which is what navigating does', () => {
    silenceReactErrorLogging();

    const { rerender } = render(
      <ErrorBoundary resetKey="/analytics">
        <Boom />
      </ErrorBoundary>
    );

    expect(screen.queryByText('the next screen')).not.toBeInTheDocument();

    // The router location is passed as the reset key, so moving to
    // another route clears the boundary without a reload.
    rerender(
      <ErrorBoundary resetKey="/leads">
        <p>the next screen</p>
      </ErrorBoundary>
    );

    expect(screen.getByText('the next screen')).toBeInTheDocument();
  });

  it('does not recover on a rerender that is not a navigation', () => {
    silenceReactErrorLogging();

    const { rerender } = render(
      <ErrorBoundary resetKey="/analytics">
        <Boom />
      </ErrorBoundary>
    );

    // Same key: the operator is still on the screen that broke, so
    // re-rendering the broken child would only throw again.
    rerender(
      <ErrorBoundary resetKey="/analytics">
        <p>should stay hidden</p>
      </ErrorBoundary>
    );

    expect(screen.queryByText('should stay hidden')).not.toBeInTheDocument();
  });

  it('reports the failure to the console and nowhere else', () => {
    const logged = silenceReactErrorLogging();
    const sent = globalThis.fetchMock;

    render(
      <ErrorBoundary>
        <Boom />
      </ErrorBoundary>
    );

    expect(logged).toHaveBeenCalled();

    /*
     * A render error carries whatever was being rendered — a lead's name,
     * a visitor's question. This plugin's promise is that the customer's
     * data stays on their server, so there is no reporting endpoint and
     * there must not become one by accident.
     */
    expect(sent).not.toHaveBeenCalled();
  });
});
