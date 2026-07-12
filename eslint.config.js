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
    ignores: ['asset/dist/**', 'node_modules/**', 'vendor/**'],
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
    files: ['scripts/**/*.js', 'vite.config.ts', 'eslint.config.js'],
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
];
