// Flat config (ESLint 9+/10).
// Lints the Svelte client only; PHP linting is handled separately
// (composer run-script lint when we add it).

import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import sveltePlugin from 'eslint-plugin-svelte';
import svelteParser from 'svelte-eslint-parser';
import globals from 'globals';

export default [
  {
    // scripts/theme-token-guard.cjs is synced verbatim from IWAC-theme by its
    // `npm run sync:tokens`, and linted there.
    // test-results/ holds Playwright's traces and Lighthouse's reports.
    ignores: [
      'asset/dist/**',
      'node_modules/**',
      'vendor/**',
      'scripts/theme-token-guard.cjs',
      'test-results/**',
      'playwright-report/**',
    ],
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  ...sveltePlugin.configs['flat/recommended'],
  {
    languageOptions: {
      globals: { ...globals.browser },
      parserOptions: {
        // Svelte 5 with runes — make sure the parser knows we're using
        // ESM and TypeScript.
        ecmaVersion: 2022,
        sourceType: 'module',
      },
    },
    rules: {
      // House style: prefer named exports, but allow default exports for
      // Svelte components and Vite config (their conventions require it).
      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
      ],
      'no-console': ['warn', { allow: ['warn', 'error'] }],
    },
  },
  {
    // The two CI-gating Node scripts get linted too (they used to escape
    // their own quality gates entirely). Node globals + console allowed —
    // their console output IS their interface.
    // The Playwright config and suite are Node too (their page.evaluate
    // callbacks keep the browser globals from above).
    files: [
      'scripts/**/*.js',
      'vite.config.ts',
      'eslint.config.js',
      'playwright.config.ts',
      'tests/browser/**/*.ts',
    ],
    languageOptions: {
      globals: { ...globals.node },
    },
    rules: {
      'no-console': 'off',
    },
  },
  {
    // The Svelte plugin needs the .svelte parser explicitly per-file.
    // `.svelte.ts` / `.svelte.js` are Svelte 5's rune-in-TS files —
    // svelte-eslint-parser v1+ handles them via the nested ts parser.
    files: ['**/*.svelte', '**/*.svelte.ts', '**/*.svelte.js'],
    languageOptions: {
      parser: svelteParser,
      parserOptions: {
        parser: tseslint.parser,
        extraFileExtensions: ['.svelte'],
        svelteFeatures: { runes: true },
      },
    },
  },
  {
    // Type-aware rules for the shipped client and both test suites. The
    // project service reads tsconfig.json, which includes the .svelte files,
    // tests/client and tests/browser. A promise nobody awaits drops its
    // rejection silently — in a Playwright test, an un-awaited expect is one
    // that never fails — and an async handler passed where a void callback is
    // expected does the same; deliberate fire-and-forget calls are marked
    // with `void`.
    files: [
      'src/svelte/**/*.ts',
      'src/svelte/**/*.svelte',
      'src/svelte-shared/**/*.ts',
      'src/svelte-shared/**/*.svelte',
      'tests/client/**/*.ts',
      'tests/browser/**/*.ts',
      'playwright.config.ts',
    ],
    languageOptions: {
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
        extraFileExtensions: ['.svelte'],
      },
    },
    rules: {
      '@typescript-eslint/await-thenable': 'error',
      '@typescript-eslint/no-floating-promises': 'error',
      '@typescript-eslint/no-misused-promises': 'error',
    },
  },
];
