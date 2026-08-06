import { render, screen, cleanup } from '@testing-library/preact';
import { createElement } from 'preact';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Consent } from '../../public-widget/src/components/Consent';
import { accepted, remember } from '../../public-widget/src/lib/api';
import { strings } from '../../public-widget/src/lib/i18n';

/**
 * The gate the product's privacy claim rests on.
 *
 * With consent required, the widget makes *no request at all* until a
 * visitor agrees — not even the page-view ping every other install sends
 * on load. That sentence is in the component's docblock, the changelog
 * and the privacy screen, and until now nothing checked it.
 *
 * Written without JSX on purpose. The widget is Preact and the admin app
 * is React; a `.tsx` file here would be compiled by whichever JSX
 * transform the shared config picked, and getting that wrong fails with a
 * message about the runtime rather than about the gate. `createElement`
 * is unambiguous, and the file is short enough that the lost readability
 * is a fair trade for losing a whole category of confusion.
 */

const labels = strings('en');

function gate(overrides: { onAccept?: () => void; onDecline?: () => void; text?: string } = {}) {
  return render(
    createElement(Consent, {
      labels,
      text: overrides.text ?? 'We record what you type so a person can follow up.',
      onAccept: overrides.onAccept ?? ((): void => {}),
      onDecline: overrides.onDecline ?? ((): void => {}),
    })
  );
}

afterEach(cleanup);

describe('the consent gate', () => {
  it('offers accepting and declining as equals', () => {
    gate();

    /*
     * Both are buttons. A decline styled as a link below the primary
     * action is the same dark pattern as a capture card with no way out,
     * and the component's docblock commits to not doing that.
     */
    expect(screen.getByRole('button', { name: labels.consentAccept })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: labels.consentDecline })).toBeInTheDocument();
  });

  it('shows the site owner’s own wording', () => {
    gate({ text: 'A bespoke notice the operator wrote.' });

    expect(screen.getByText('A bespoke notice the operator wrote.')).toBeInTheDocument();
  });

  it('reports which button was pressed, and only that one', () => {
    const onAccept = vi.fn();
    const onDecline = vi.fn();

    gate({ onAccept, onDecline });

    screen.getByRole('button', { name: labels.consentDecline }).click();

    expect(onDecline).toHaveBeenCalledTimes(1);
    expect(onAccept).not.toHaveBeenCalled();
  });

  it('makes no network request merely by being shown', () => {
    gate();

    // The gate is what a visitor sees *before* anything is recorded. If
    // rendering it costs a request, the promise is already broken.
    expect(globalThis.fetchMock).not.toHaveBeenCalled();
  });
});

describe('remembering a consent decision', () => {
  /*
   * `accepted()` reads through a try/catch that returns null when storage
   * is unavailable, so "no consent recorded" is also what a completely
   * broken localStorage looks like. This test therefore asserts the
   * round trip first: if the stub were not real storage, `remember()`
   * would not be readable back and this fails immediately, rather than
   * leaving the negative cases to pass for the wrong reason.
   */
  it('remembers acceptance, so the gate is not a banner that never learns', () => {
    expect(accepted()).toBe(false);

    remember();

    expect(accepted()).toBe(true);
  });

  it('forgets when the visitor clears their storage', () => {
    remember();
    expect(accepted()).toBe(true);

    localStorage.clear();

    // Somebody who cleared their storage genuinely is somebody the site
    // has no record of agreeing, so asking again is the honest answer.
    expect(accepted()).toBe(false);
  });

  it('treats a stored value that is not agreement as no consent', () => {
    localStorage.setItem('hvc.consent', 'maybe');

    // The check is `=== '1'`, not truthiness. Anything else — a value
    // from an older build, another plugin, a half-written write — means
    // ask again rather than assume yes.
    expect(accepted()).toBe(false);
  });

  it('does not leak one test’s decision into the next', () => {
    // Guards the setup file's per-test storage reset. Without it, the
    // first test to accept would silently satisfy every test after it
    // and the gate would look like it worked while never being shown.
    expect(accepted()).toBe(false);
  });
});
