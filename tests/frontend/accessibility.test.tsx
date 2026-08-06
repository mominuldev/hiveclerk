import { render, waitFor } from '@testing-library/react';
import axe from 'axe-core';
import { describe, expect, it } from 'vitest';
import { App } from '@/App';

/**
 * Every admin screen, checked against WCAG 2.1 A and AA.
 *
 * The Definition of Done has asked for keyboard reachability, visible focus
 * and honoured `prefers-reduced-motion` on every screen since sprint 1, and
 * every sprint has claimed it on the strength of somebody looking at the
 * page. This is the first thing that can fail a build over it.
 *
 * Two things this cannot see, both of which matter more than they sound:
 *
 * **Contrast.** jsdom has no layout engine, so every rule needing a box
 * reports "incomplete" rather than pass or fail. That is not a corner: when
 * `tools/a11y.mjs` first ran against a real browser it found 92 contrast
 * failures on 23 screens, and this file was green throughout.
 *
 * **Populated screens.** There is no server here and `fetch` throws, so
 * these render the shell, the tab bars and the empty states. `/leads/table`
 * audits *three buttons and no table*; the same route in a browser has 341
 * elements. What passes here is the chrome.
 *
 * So this is a regression net for the markup-level rules — labels, roles,
 * heading order, landmarks — that runs in a second on every `npm run check`.
 * `npm run test:a11y` is the actual audit. Treating this file as the whole
 * of it would be the same mistake as the screenshot it replaces.
 */

const SCREENS = [
  '/dashboard',
  '/clerks',
  '/conversations',
  '/leads/pipeline',
  '/leads/table',
  '/leads/scoring',
  '/email/sequences',
  '/knowledge/sources',
  '/knowledge/gaps',
  '/knowledge/playground',
  '/integrations/connectors',
  '/analytics/overview',
  '/settings/providers',
  '/settings/privacy',
  '/settings/system',
] as const;

/**
 * The ruleset. Deliberately the published standard rather than a list we
 * chose, so that "passes" means something a customer's accessibility
 * consultant would also recognise.
 */
const RULES: axe.RunOptions = {
  runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
};

/** A violation, rendered so a failure names the fix and not just the rule. */
function describeViolations(results: axe.AxeResults): string {
  return results.violations
    .map((violation) => {
      const where = violation.nodes
        .slice(0, 3)
        .map((node) => `      ${node.target.join(' ')}`)
        .join('\n');

      return `  [${violation.impact}] ${violation.id}: ${violation.help}\n${where}`;
    })
    .join('\n');
}

describe('the admin screens', () => {
  it.each(SCREENS)(
    '%s has no WCAG A or AA violations',
    async (path) => {
      window.location.hash = `#${path}`;

      const { container } = render(<App />);

      await waitFor(() => expect(document.querySelector('nav')).toBeInTheDocument());

      const results = await axe.run(container, RULES);

      expect(results.violations.length, `\n${describeViolations(results)}`).toBe(0);
    },
    20_000
  );
});
