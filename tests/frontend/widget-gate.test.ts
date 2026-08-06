import { render, cleanup, waitFor } from '@testing-library/preact';
import { createElement } from 'preact';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Widget } from '../../public-widget/src/app';
import { remember } from '../../public-widget/src/lib/api';
import type { WidgetBoot } from '../../public-widget/src/types';

/**
 * The compliance claim itself: with the gate on, the widget makes no
 * request at all until the visitor agrees — not even the page-view ping
 * every other install sends on load.
 *
 * `tests/frontend/consent.test.ts` covers the gate's component and the
 * memory behind it. Neither actually exercises the suppression, which
 * lives in the effect at `public-widget/src/app.tsx:69-75` and in the
 * `useState` initialiser above it. Consent is read during the first
 * render rather than in an effect precisely because an effect would run
 * *after* the ping had already fired, and that ordering is the whole
 * defence. Only rendering the real `Widget` tests it.
 *
 * The negative assertion here is worthless without the controls below
 * it: "no request was made" also describes a widget that never pings at
 * all, or one whose ping fails to reach a stub. Every suppression test
 * is paired with a case that must produce a request.
 */

const BOOT = {
  agent: {
    uuid: '2f1c8a90-0000-4000-8000-000000000000',
    name: 'Clerk',
    avatar_url: null,
    greeting: 'How can I help?',
    widget_config: {},
    locale: 'en',
    branding: { show_badge: true, label: 'Hiveclerk' },
  },
  capabilities: { streaming: false, handoff: false, feedback: false },
  consent: { required: true, text: 'We record what you type.' },
  rest_url: 'https://example.test/wp-json/hiveclerk/v1',
  version: '0.1.0-dev',
} as unknown as WidgetBoot;

/** The same payload with the gate turned off. */
function ungated(): WidgetBoot {
  return { ...BOOT, consent: { required: false, text: null } };
}

/**
 * Replaces the throwing default with a stub that answers.
 *
 * The default is hostile on purpose, which is right for asserting that
 * nothing was requested and useless for asserting that something was.
 */
function allowRequests(): ReturnType<typeof vi.fn> {
  const fetching = vi.fn(async () =>
    new Response(JSON.stringify({ data: {} }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  );

  globalThis.fetchMock = fetching;
  vi.stubGlobal('fetch', fetching);

  return fetching;
}

function mount(boot: WidgetBoot = BOOT) {
  const host = document.createElement('div');
  document.body.append(host);

  return render(createElement(Widget, { boot, host }));
}

/*
 * Effects are what fire the ping, so every assertion has to outlive them.
 *
 * Waits on the launcher rather than on any text. A closed widget renders
 * an icon-only button and no words at all, so waiting for text would
 * time out on a perfectly healthy render — and then report the timeout
 * instead of whatever the test was actually about.
 */
async function afterEffectsHaveRun(): Promise<void> {
  await waitFor(() => expect(document.querySelector('button')).not.toBeNull());
  await new Promise((resolve) => setTimeout(resolve, 0));
}

/** Opens the panel, which is where the gate is shown. */
function openThePanel(): void {
  document.querySelector('button')?.click();
}

beforeEach(() => {
  document.body.innerHTML = '';
});

afterEach(cleanup);

describe('a visitor who has not agreed', () => {
  it('costs the site no request at all, including the page-view ping', async () => {
    const requested = allowRequests();

    mount();
    await afterEffectsHaveRun();

    // Not "no chat request" — no request. A page view recorded before
    // the visitor agreed is the exact row the gate exists to prevent,
    // and it is the one that would otherwise be written on every page of
    // the site whether or not anybody opens the panel.
    expect(requested).not.toHaveBeenCalled();
  });

  it('is shown the notice when the panel is opened, not a broken widget', async () => {
    const requested = allowRequests();

    mount();
    await afterEffectsHaveRun();

    openThePanel();
    await waitFor(() =>
      expect(document.body.textContent).toContain('We record what you type.')
    );

    // Opening the panel is the most engaged thing a visitor can do short
    // of typing, and it still buys the site nothing until they agree.
    expect(requested).not.toHaveBeenCalled();
  });
});

describe('the controls that make the suppression mean something', () => {
  it('pings when the operator has not turned the gate on', async () => {
    const requested = allowRequests();

    mount(ungated());
    await afterEffectsHaveRun();

    /*
     * Without this, the test above passes for a widget that never pings
     * — which is to say it would pass against a bug that silently lost
     * every page view, and would keep passing after the gate was
     * deleted.
     */
    expect(requested).toHaveBeenCalled();
  });

  it('pings for a visitor who agreed on an earlier page view', async () => {
    remember();

    const requested = allowRequests();

    mount();
    await afterEffectsHaveRun();

    // The gate is remembered, not re-asked. If this did not ping, the
    // gate would be a banner that blocks telemetry forever.
    expect(requested).toHaveBeenCalled();
  });
});

describe('what the page-view ping is', () => {
  it('is one request, and it goes to the plugin’s own REST namespace', async () => {
    const requested = allowRequests();

    mount(ungated());
    await afterEffectsHaveRun();

    expect(requested).toHaveBeenCalledTimes(1);

    // Self-hosted is the product's promise: the only host a widget on a
    // page nobody chats on should ever contact is the customer's own.
    const [target] = requested.mock.calls[0] as [string];
    expect(String(target)).toContain('/wp-json/hiveclerk/v1');
  });
});
