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

const bundles = [
  {
    entry: 'src/svelte/main.ts',
    fileName: 'iwac-search',
    name: 'IwacSearch',
  },
  {
    entry: 'src/svelte-admin/main.ts',
    fileName: 'iwac-search-admin',
    name: 'IwacSearchAdmin',
  },
];

// Vite's lib mode takes a single entry, so we select one per build via
// the `--mode` flag (`vite build --mode admin`) or an env var. The npm
// scripts drive both in sequence; CI runs them in parallel via matrix.
const activeBundleName = process.env.IWAC_BUNDLE ?? 'public';
const active = activeBundleName === 'admin' ? bundles[1] : bundles[0];

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
