import { defineConfig } from 'vitest/config';
import { svelte } from '@sveltejs/vite-plugin-svelte';

/**
 * Unit tests for the client's pure seams.
 *
 * Separate from vite.config.ts because that file is a two-bundle LIB build
 * driven by the IWAC_BUNDLE env var — reusing it here would make every test
 * run inherit a bundle target it has no use for.
 *
 * jsdom, not node: `urlState` reads `window.location` / `history` and the
 * codecs are only meaningful against a real `URL` / `URLSearchParams`.
 *
 * The svelte plugin is loaded so `*.svelte.ts` composables (which compile
 * runes) can be imported by tests. Component rendering is deliberately NOT
 * covered — that needs a DOM-testing stack and would mostly assert markup;
 * the value here is in the codecs, query builders and state rules that
 * silently changed behaviour before (see A1 in docs/module-review-2026-07.md).
 */
export default defineConfig({
  plugins: [svelte()],
  test: {
    environment: 'jsdom',
    include: ['tests/client/**/*.test.ts'],
    // The bundles live in asset/dist; nothing there is a test.
    exclude: ['node_modules/**', 'asset/**', 'vendor/**'],
    restoreMocks: true,
  },
});
