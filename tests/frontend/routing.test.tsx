import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { App } from '@/App';

/**
 * Where each sidebar link actually lands.
 *
 * The React Router 8 upgrade was verified by taking a screenshot of one
 * screen, which cannot catch the failure this file is about. The route
 * table ends in `<Route path="*" element={<Navigate to="/dashboard" />} />`,
 * so a path that stops matching does not blank the screen or throw — it
 * quietly redirects. Rename `leads/pipeline` and clicking "Leads" lands
 * the operator on the Dashboard, which looks like a working admin.
 *
 * The sidebar also keeps its own `NAV` table of destinations, separate
 * from the route declarations. Nothing but this test holds the two
 * together.
 *
 * Deliberately drives the real `App` — the real router, the real nested
 * routes, the real index redirects — rather than a rebuilt copy of the
 * route table, because a copy would agree with itself after a rename.
 * `boot()` falls back to a payload with every capability false, and the
 * shell routes fine on it: navigation is not capability-gated, and if it
 * ever becomes so, this failing is the right answer.
 */

/**
 * Every destination the sidebar offers, and the path it must settle on
 * once the index redirects have run.
 *
 * The right-hand side is the point. A section with tabs redirects to its
 * first tab rather than rendering an index, so the tab bar always has an
 * active tab and a bookmarked `/leads` still lands somewhere nameable.
 */
const LANDINGS: ReadonlyArray<readonly [string, string]> = [
  ['/dashboard', '#/dashboard'],
  ['/clerks', '#/clerks'],
  ['/conversations', '#/conversations'],
  ['/leads', '#/leads/pipeline'],
  ['/email', '#/email/sequences'],
  ['/knowledge', '#/knowledge/sources'],
  ['/integrations', '#/integrations/connectors'],
  ['/workflows', '#/workflows'],
  ['/analytics', '#/analytics/overview'],
  ['/settings', '#/settings/providers'],
];

async function visit(path: string): Promise<void> {
  window.location.hash = `#${path}`;

  render(<App />);

  // The index redirects resolve during render, but asserting on the hash
  // before React has committed would read the value we just wrote and
  // pass regardless of whether the router did anything.
  await waitFor(() => expect(document.querySelector('nav')).toBeInTheDocument());
}

describe('sidebar navigation', () => {
  it.each(LANDINGS)('%s settles on %s', async (path, expected) => {
    await visit(path);

    expect(window.location.hash).toBe(expected);
  });

  it.each(LANDINGS.filter(([path]) => path !== '/dashboard'))(
    '%s is a real route rather than a silent fall through to the dashboard',
    async (path) => {
      await visit(path);

      /*
       * The specific thing the catch-all hides. Checked separately from
       * the landing assertion above because this is the failure that
       * looks like success: the admin renders, the sidebar renders, and
       * the operator is simply on the wrong screen.
       */
      expect(window.location.hash).not.toBe('#/dashboard');
    }
  );
});

describe('paths that are not in the sidebar', () => {
  it('sends an unknown path to the dashboard rather than nowhere', async () => {
    await visit('/a-screen-that-was-renamed');

    // The catch-all is deliberate: a stale bookmark from an older
    // version should land on a working screen, not an empty frame.
    expect(window.location.hash).toBe('#/dashboard');
  });

  it('sends the bare root to the dashboard', async () => {
    await visit('/');

    expect(window.location.hash).toBe('#/dashboard');
  });

  it('keeps a deep link with an id intact instead of redirecting it', async () => {
    await visit('/clerks/2f1c8a90-0000-4000-8000-000000000000');

    // Parameterised routes are the ones a rename breaks most quietly,
    // because nobody has one bookmarked to notice.
    expect(window.location.hash).toBe('#/clerks/2f1c8a90-0000-4000-8000-000000000000');
  });
});

describe('the shell around the routes', () => {
  it('renders the sidebar on a routed screen, so there is a way out', async () => {
    await visit('/leads');

    expect(screen.getByRole('navigation', { name: 'Sections' })).toBeInTheDocument();
  });

  it('renders the full-screen sequence builder outside the shell', async () => {
    await visit('/email/sequences/2f1c8a90-0000-4000-8000-000000000000');

    // A tab bar above an unsaved sequence offers to navigate away from
    // work in progress, so the builder is declared outside the tabbed
    // section on purpose.
    expect(window.location.hash).toBe(
      '#/email/sequences/2f1c8a90-0000-4000-8000-000000000000'
    );
  });
});
