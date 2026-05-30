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
 *   iwac-search-admin.{js,css} — admin CRUD app for curated browse pages
 *                                (mounts under /admin/iwac-search/...
 *                                 via [data-iwac-admin-root])
 *
 *   iwac-search-header.{js,css} — tiny, framework-free header typeahead
 *                                (enhances the IWAC-theme header search box
 *                                 on every site page; navigates to /search)
 *
 * Why separate bundles instead of one admin + public SPA:
 *   - Public pages should not ship admin-CRUD code the anonymous visitor
 *     can't use anyway. ~25 KB gzipped saved per public page.
 *   - Different mount contracts, different state shapes, different
 *     security tradeoffs (admin needs CSRF, public doesn't).
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
  admin: {
    entry: 'src/svelte-admin/main.ts',
    fileName: 'iwac-search-admin',
    name: 'IwacSearchAdmin',
  },
  header: {
    entry: 'src/svelte/header.ts',
    fileName: 'iwac-search-header',
    name: 'IwacSearchHeader',
  },
} as const;

// Vite's lib mode takes a single entry, so we select one per build via the
// IWAC_BUNDLE env var (`cross-env IWAC_BUNDLE=admin vite build`). The npm
// scripts drive all three in sequence; CI runs them in parallel via matrix.
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
        // Force a stable CSS filename per bundle — Module.php + the
        // admin controller both reference these by literal path.
        assetFileNames: (asset) =>
          asset.name?.endsWith('.css') ? `${active.fileName}.css` : 'assets/[name][extname]',
      },
    },
  },
});
