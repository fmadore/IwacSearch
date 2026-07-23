import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { resolve } from 'node:path';

/**
 * Two independent IIFE bundles, emitted side by side into asset/dist/:
 *
 *   iwac-search.{js,css}       — public discovery client
 *                                (mounts on /search, /browse/{slug}, and
 *                                 page blocks via [data-iwac-search-root])
 *
 *   iwac-search-header.{js,css} — tiny, framework-free header typeahead
 *                                (enhances the IWAC-theme header search box
 *                                 on every site page; navigates to /search)
 *
 * Why two bundles instead of one:
 *   - The public discovery app loads only on the search surfaces; the header
 *     typeahead is framework-free and loads site-wide, so it must stay tiny.
 *   - Build failures in one don't block the other.
 *
 * Both bundles are IIFE for the same reasons the public client was:
 * Omeka pages are server-rendered HTML, no module loader, the
 * compiled file auto-mounts on DOMContentLoaded.
 */

const bundles = {
  public: {
    entry: 'src/svelte/main.ts',
    fileName: 'iwac-search',
    name: 'IwacSearch',
  },
  header: {
    entry: 'src/svelte/header.ts',
    fileName: 'iwac-search-header',
    name: 'IwacSearchHeader',
  },
} as const;

// Vite's lib mode takes a single entry, so we select one per build via the
// IWAC_BUNDLE env var (`cross-env IWAC_BUNDLE=header vite build`). The npm
// `build` script drives both in sequence — locally and in CI (one job, so
// the committed-bundle diff check sees both outputs together).
const activeBundleName = (process.env.IWAC_BUNDLE ?? 'public') as keyof typeof bundles;
const active = bundles[activeBundleName] ?? bundles.public;

export default defineConfig({
  plugins: [svelte()],
  build: {
    outDir: 'asset/dist',
    // Don't wipe the sibling bundle's output on each build.
    emptyOutDir: false,
    cssCodeSplit: false,
    sourcemap: false,
    target: 'es2022',
    lib: {
      entry: resolve(__dirname, active.entry),
      formats: ['iife'],
      name: active.name,
      fileName: () => `${active.fileName}.js`,
    },
    rollupOptions: {
      output: {
        // Force a stable CSS filename per bundle — Module.php references
        // these by literal path.
        assetFileNames: (asset) =>
          asset.name?.endsWith('.css') ? `${active.fileName}.css` : 'assets/[name][extname]',
      },
    },
  },
});
