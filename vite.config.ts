import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'node:path';

/**
 * The admin bundle is built to assets/admin with a manifest. PHP reads the
 * manifest to resolve hashed filenames, so no build output path is ever
 * hard-coded on the server side.
 *
 * base is relative because the plugin directory URL is unknown at build
 * time: it differs per site, and multisite installs move it again.
 */
export default defineConfig({
  base: './',

  plugins: [react(), tailwindcss()],

  resolve: {
    alias: {
      '@': resolve(__dirname, 'admin-app/src'),
    },
  },

  build: {
    outDir: 'assets/admin',
    emptyOutDir: true,
    manifest: true,
    target: 'es2022',
    sourcemap: false,
    reportCompressedSize: true,
    rollupOptions: {
      input: resolve(__dirname, 'admin-app/src/main.tsx'),
      output: {
        // Recharts and React are large and change rarely. Splitting them
        // keeps the app chunk small enough to re-download cheaply when we
        // ship a fix.
        manualChunks: {
          react: ['react', 'react-dom', 'react-router'],
          charts: ['recharts'],
        },
      },
    },
  },

  server: {
    cors: true,
  },
});
