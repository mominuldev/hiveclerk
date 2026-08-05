import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import reactHooks from 'eslint-plugin-react-hooks';

export default tseslint.config(
  /*
   * `landing-page/` is the marketing site, not the plugin. It ships
   * separately, has its own globals and its own toolchain, and linting it
   * with the plugin's config produced 34 errors that were noise rather
   * than defects — enough to make `npm run check` a gate nobody could
   * read. Excluded so a real regression in `admin-app/` or
   * `public-widget/` is visible on the first line of output.
   */
  { ignores: ['assets/**', 'node_modules/**', 'vendor/**', 'landing-page/**'] },

  js.configs.recommended,
  ...tseslint.configs.recommended,

  // Node-side tooling: build scripts and the screenshot harness.
  {
    files: ['tools/**/*.{js,mjs}', '*.config.{js,mjs}'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
      globals: {
        process: 'readonly',
        console: 'readonly',
        document: 'readonly',
        getComputedStyle: 'readonly',
        // Declared for the bodies passed to page.evaluate() and
        // addInitScript(): they are written here but execute inside the
        // browser, where these exist and Node's globals do not.
        window: 'readonly',
        Response: 'readonly',
        __dirname: 'readonly',
        // Modern Node built-ins. The SSE probe reads a response stream
        // by hand and times each chunk's arrival, which needs all four.
        fetch: 'readonly',
        performance: 'readonly',
        TextDecoder: 'readonly',
        Buffer: 'readonly',
      },
    },
    rules: { 'no-console': 'off' },
  },

  {
    files: ['admin-app/**/*.{ts,tsx}', 'public-widget/**/*.{ts,tsx}'],
    plugins: { 'react-hooks': reactHooks },
    languageOptions: {
      ecmaVersion: 2022,
      globals: {
        window: 'readonly',
        document: 'readonly',
        console: 'readonly',
        fetch: 'readonly',
        URL: 'readonly',
        AbortSignal: 'readonly',
        HTMLElement: 'readonly',
        KeyboardEvent: 'readonly',
      },
    },
    rules: {
      ...reactHooks.configs.recommended.rules,

      /*
       * The admin is a standalone SPA. Gutenberg packages are forbidden:
       * they drag in a second React runtime, tie our release cadence to
       * WordPress core, and would make the SaaS extraction impossible.
       */
      'no-restricted-imports': [
        'error',
        {
          patterns: [
            {
              group: ['@wordpress/*'],
              message:
                'Gutenberg packages are not allowed. The admin is a standalone SPA — build the primitive in components/ui instead.',
            },
          ],
        },
      ],

      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
      ],
      '@typescript-eslint/consistent-type-imports': 'error',
      'no-console': ['error', { allow: ['warn', 'error'] }],
    },
  }
);
