import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { resolve } from 'node:path';

/**
 * Build the Svelte client as a self-contained IIFE that any HTML page
 * can drop in via a single <script defer src=".../iwac-search.js"></script>.
 *
 * Why IIFE and not ESM:
 *   - Omeka pages are server-rendered without a module bundler; an IIFE
 *     loads with a plain <script> tag and immediately executes its
 *     auto-mount logic (walking [data-iwac-search-root] elements).
 *   - The CSS extracted from .svelte components is emitted alongside as
 *     iwac-search.css; Module.php injects the JS via headScript and the
 *     bundle imports its own stylesheet at runtime.
 *
 * The bundle is intentionally NOT split into chunks — a single ~80 KB
 * gzipped file is faster than parallel small loads on a fresh page,
 * especially over the warm IIIF FastCGI cache common to IWAC visitors.
 */
export default defineConfig({
  plugins: [svelte()],
  build: {
    outDir: 'asset/dist',
    emptyOutDir: true,
    cssCodeSplit: false,
    sourcemap: false,
    target: 'es2022',
    lib: {
      entry: resolve(__dirname, 'src/svelte/main.ts'),
      formats: ['iife'],
      // The IIFE wrapper exposes a single global; nobody calls into it
      // directly (auto-mount runs from main.ts), but Vite requires a name.
      name: 'IwacSearch',
      fileName: () => 'iwac-search.js',
    },
    rollupOptions: {
      output: {
        // Force a stable CSS filename — Module.php expects iwac-search.css
        assetFileNames: (asset) =>
          asset.name?.endsWith('.css') ? 'iwac-search.css' : 'assets/[name][extname]',
      },
    },
  },
});
