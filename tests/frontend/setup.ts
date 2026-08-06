import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, beforeEach, vi } from 'vitest';

/**
 * Shared setup for the front-end suite.
 *
 * Two things are deliberately hostile here.
 *
 * `fetch` is replaced with a spy that throws by default. A test that
 * makes an unexpected request fails on the request rather than on some
 * later assertion about state — which matters most for the consent gate,
 * whose entire promise is that no request happens at all until a visitor
 * agrees. A permissive stub would let that promise break silently and
 * report the failure as something else.
 *
 * `localStorage` is cleared between tests. The widget remembers consent
 * there, so a test that accepted would otherwise hand its decision to
 * every test after it, and the gate would appear to work while never
 * being shown.
 */

declare global {
  var fetchMock: ReturnType<typeof vi.fn>;
}

/**
 * An in-memory Storage.
 *
 * jsdom does provide one, but Vitest exposes the environment's globals as
 * a copied object rather than the live jsdom `Window` — `window.localStorage`
 * is present as a property name and reads as `undefined`, which looks
 * exactly like a browser API that does not exist. Supplying our own is
 * shorter than working out how to reach jsdom's, and it is what a test
 * wants anyway: no shared origin, no quota, and a clean slate that is
 * clean because it was just constructed.
 */
function memoryStorage(): Storage {
  const entries = new Map<string, string>();

  return {
    get length() {
      return entries.size;
    },
    clear: () => entries.clear(),
    getItem: (key: string) => entries.get(key) ?? null,
    key: (index: number) => [...entries.keys()][index] ?? null,
    removeItem: (key: string) => void entries.delete(key),
    setItem: (key: string, value: string) => void entries.set(key, String(value)),
  } as Storage;
}

/**
 * jsdom does not implement `matchMedia`, and it is not pretending to —
 * media queries need a layout engine it does not have. The theme hook
 * calls it to resolve `auto`, so without this every render of the shell
 * dies on a missing function rather than on anything under test.
 *
 * Reports "not dark" and never changes. A test that cares about the dark
 * theme should say so by stubbing this itself; silently defaulting to
 * dark here would make the light theme the untested one.
 */
function stubMatchMedia(): void {
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      addListener: vi.fn(),
      removeListener: vi.fn(),
      dispatchEvent: vi.fn(),
    }))
  );
}

/**
 * A canvas that returns nothing rather than throwing.
 *
 * axe-core reads pixels back from a canvas to decide whether a glyph is an
 * icon-font ligature. jsdom has no 2D context and raises "Not implemented"
 * for every element it checks, which buries the actual audit output under
 * hundreds of lines of stack trace.
 *
 * Returning null is what a browser does for an unsupported context type,
 * and axe already handles it — it means "cannot tell", not "no problem".
 */
function stubCanvas(): void {
  HTMLCanvasElement.prototype.getContext = (() =>
    null) as HTMLCanvasElement['getContext'];
}

beforeEach(() => {
  stubMatchMedia();
  stubCanvas();

  globalThis.fetchMock = vi.fn(() => {
    throw new Error(
      'Unexpected network request. A test that means to allow one should set its own fetch behaviour.'
    );
  });

  vi.stubGlobal('fetch', globalThis.fetchMock);
  vi.stubGlobal('localStorage', memoryStorage());
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});
