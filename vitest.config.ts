import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

/**
 * Front-end tests.
 *
 * Separate from vite.config.ts rather than folded into it. The build
 * config sets an output directory, a manifest and manual chunks, none of
 * which mean anything to a test run — and inheriting them means a change
 * to how the bundle is split can break the tests for reasons that have
 * nothing to do with the code under test.
 *
 * Tailwind is deliberately absent. These assert behaviour, not appearance:
 * what renders after a click, whether a request was made before consent.
 * Processing the stylesheet would add seconds per run to produce classes
 * nothing here reads.
 */
export default defineConfig({
  plugins: [react()],

  resolve: {
    alias: {
      '@': resolve(__dirname, 'admin-app/src'),
    },
  },

  test: {
    environment: 'jsdom',

    /*
     * jsdom defaults to about:blank, which is an opaque origin, and an
     * opaque origin has no localStorage. The widget remembers consent
     * there, so without a real URL the gate cannot be tested at all —
     * and the failure looks like a missing global rather than a missing
     * origin.
     */
    environmentOptions: {
      jsdom: { url: 'https://example.test/' },
    },

    globals: true,
    setupFiles: ['./tests/frontend/setup.ts'],
    include: ['tests/frontend/**/*.test.{ts,tsx}'],
    // The suite is small and fast; a slow test here means something is
    // waiting on a timer that a test should have controlled.
    testTimeout: 5000,
  },
});
