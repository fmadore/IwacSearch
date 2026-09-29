#!/usr/bin/env node
/**
 * Bundle-size budget — `npm run check:size`, the last step of `npm run build`.
 *
 * The sizes of these bundles used to live in prose: Module.php described the
 * site-wide header typeahead as "~34 KB raw / ~13 KB gzipped" and header.ts
 * called the search app "~90 KB", while the real figures were 25 KB and
 * 177 KB. A number in a comment is right only on the day it is written, so
 * the budget is asserted here instead, and the comments point at this file.
 *
 * The header bundle matters most: it loads on EVERY public page. Beyond its
 * byte budget it is held to one structural rule — the app's STRINGS table
 * must not be in it. It was, for three strings, until 3.21 (a top-level
 * `SORT_VALUES` computed by calling `sortOptions()` kept `translate()` and
 * every key of both locales alive), which cost ~9 KB raw on every page.
 */
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const DIST = join(dirname(fileURLToPath(import.meta.url)), '..', 'asset', 'dist');

/** Gzipped budgets in bytes, with headroom above the current size. */
const BUDGETS = {
  'iwac-search-header.js': 7_000,
  'iwac-search-header.css': 1_500,
  'iwac-search.js': 64_000,
  'iwac-search.css': 10_000,
};

/** Strings that prove the app's STRINGS table leaked into the header bundle. */
const HEADER_MUST_NOT_CONTAIN = ['search_unavailable', 'clear_all_filters', 'announce_page'];

let failed = false;
for (const [file, budget] of Object.entries(BUDGETS)) {
  const bytes = readFileSync(join(DIST, file));
  const gz = gzipSync(bytes, { level: 9 }).length;
  const ok = gz <= budget;
  failed ||= !ok;
  console.log(
    `${ok ? '✓' : '✗'} ${file.padEnd(24)} ${String(bytes.length).padStart(7)} B raw  ${String(gz).padStart(6)} B gz  (budget ${budget} B gz)`,
  );
}

const header = readFileSync(join(DIST, 'iwac-search-header.js'), 'utf8');
const leaked = HEADER_MUST_NOT_CONTAIN.filter((key) => header.includes(key));
if (leaked.length) {
  failed = true;
  console.error(
    `✗ iwac-search-header.js carries the app's string table (${leaked.join(', ')}): something the header imports reads STRINGS. ` +
      'Import translateSuggest(), not translate(), and keep module-level code free of calls into i18n.',
  );
} else {
  console.log("✓ iwac-search-header.js carries none of the app's string table");
}

if (failed) process.exit(1);
