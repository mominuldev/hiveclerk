import { defineConfig } from 'vite';
import { resolve } from 'node:path';

/**
 * The public widget is built separately from the admin, to a fixed
 * filename and with no manifest.
 *
 * Three differences from the admin build, each with a reason:
 *
 * **One file, unhashed.** PHP enqueues it directly and busts the cache
 * with the plugin version. A manifest read on every front-end request
 * would cost a file read on the visitor's page to save nothing.
 *
 * **CSS is inside the JavaScript.** The stylesheet has to be adopted into
 * a shadow root, so it cannot be a `<link>`; keeping it in the bundle also
 * removes a render-blocking request from the customer's page, which is
 * most of the 50 ms LCP budget.
 *
 * **Preact, not React.** React and its DOM renderer are ~45 KB gzipped
 * before any of our own code — the whole widget budget is 40 KB.
 */
export default defineConfig({
  esbuild: {
    jsx: 'automatic',
    jsxImportSource: 'preact',
  },

  build: {
    outDir: 'assets/widget',
    emptyOutDir: true,
    target: 'es2020',
    sourcemap: false,
    cssCodeSplit: false,
    reportCompressedSize: true,

    lib: {
      entry: resolve(__dirname, 'public-widget/src/main.ts'),
      formats: ['es'],
      fileName: () => 'hiveclerk-widget.js',
    },

    rollupOptions: {
      output: {
        // No code splitting: a widget that loads a second chunk mid-answer
        // is a widget that fails when the visitor's connection drops after
        // the first one.
        inlineDynamicImports: true,
      },
    },
  },
});
