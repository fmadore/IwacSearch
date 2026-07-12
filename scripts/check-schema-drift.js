#!/usr/bin/env node
/**
 * Schema ↔ catalog ↔ labels drift guard.
 *
 * "Schema is the contract" (CLAUDE.md) — but three hand-maintained maps
 * mirror it and have drifted before: creator_ss was facet:true in
 * data/schema.yaml yet missing from FacetCatalog::FACETABLE_FIELDS, so the
 * admin block form silently couldn't offer it (fixed in 3.2.x, documented
 * in FacetCatalog.php). This check makes that class of bug a CI failure:
 *
 *   1. Every FacetCatalog::FACETABLE_FIELDS key must be declared in
 *      data/schema.yaml or data/schema-index.yaml with `facet: true`.
 *   2. Every FacetCatalog key must have a label in BOTH locales of
 *      i18n.ts FACET_LABELS (otherwise the UI silently falls back to
 *      humanise()).
 *
 * It also prints (informational, non-fatal) the schema facet fields NOT in
 * the catalog, so deliberate exclusions stay visible.
 *
 * Zero-dependency by design: the schema files stick to the two field
 * styles below, and the PHP/TS maps are pure data literals. If parsing
 * finds implausibly few fields (format drift), the check FAILS rather
 * than passing on an empty set.
 *
 * Run: node scripts/check-schema-drift.js  (wired into `npm run lint`)
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const read = (rel) => readFileSync(join(root, rel), 'utf8');

/**
 * Extract field names + facet flags from one of the module's schema YAMLs.
 * Handles the two styles in use:
 *   - `- { name: country_ss, type: "string[]", facet: true, ... }`
 *   - `- name: embedding` followed by indented attribute lines
 */
function parseSchemaFields(yamlText, label) {
  const fields = new Map(); // name → { facet: boolean }
  const lines = yamlText.split(/\r?\n/);
  let block = null; // { name, facet } for the multi-line style

  const commit = () => {
    if (block) {
      fields.set(block.name, { facet: block.facet });
      block = null;
    }
  };

  for (const line of lines) {
    const inline = line.match(/^\s*-\s*\{\s*name:\s*([A-Za-z0-9_]+)\s*,(.*)\}\s*(#.*)?$/);
    if (inline) {
      commit();
      fields.set(inline[1], { facet: /facet:\s*true/.test(inline[2]) });
      continue;
    }
    const blockStart = line.match(/^\s*-\s*name:\s*([A-Za-z0-9_]+)\s*(#.*)?$/);
    if (blockStart) {
      commit();
      block = { name: blockStart[1], facet: false };
      continue;
    }
    if (block) {
      if (/^\s*-\s/.test(line) || /^[A-Za-z]/.test(line)) {
        // Next list item or top-level key ends the block.
        commit();
      } else if (/^\s+facet:\s*true\s*(#.*)?$/.test(line)) {
        block.facet = true;
      }
    }
  }
  commit();

  if (fields.size < 5) {
    throw new Error(
      `${label}: parsed only ${fields.size} fields — the schema format has ` +
        'probably changed; update scripts/check-schema-drift.js.',
    );
  }
  return fields;
}

/** Keys of FacetCatalog::FACETABLE_FIELDS (a pure-data PHP const). */
function parseFacetCatalog(phpText) {
  const start = phpText.indexOf('FACETABLE_FIELDS = [');
  if (start === -1) {
    throw new Error('FacetCatalog.php: FACETABLE_FIELDS not found — update the drift check.');
  }
  const end = phpText.indexOf('];', start);
  const body = phpText.slice(start, end);
  const keys = [...body.matchAll(/^\s*'([A-Za-z0-9_]+)'\s*=>/gm)].map((m) => m[1]);
  if (keys.length < 5) {
    throw new Error(
      `FacetCatalog.php: parsed only ${keys.length} FACETABLE_FIELDS keys — format drift?`,
    );
  }
  return keys;
}

/** field → set of locales that label it, from i18n.ts FACET_LABELS. */
function parseFacetLabels(tsText) {
  const start = tsText.indexOf('const FACET_LABELS');
  if (start === -1) {
    throw new Error('i18n.ts: FACET_LABELS not found — update the drift check.');
  }
  // The literal ends at the first `};` at column 0 after the declaration.
  const end = tsText.indexOf('\n};', start);
  const body = tsText.slice(start, end);

  const byLocale = new Map();
  let locale = null;
  for (const line of body.split(/\r?\n/)) {
    const loc = line.match(/^\s{2}([a-z]{2}):\s*\{\s*$/);
    if (loc) {
      locale = loc[1];
      continue;
    }
    const entry = line.match(/^\s{4}([A-Za-z0-9_]+):\s*'/);
    if (entry && locale) {
      if (!byLocale.has(entry[1])) byLocale.set(entry[1], new Set());
      byLocale.get(entry[1]).add(locale);
    }
  }
  if (byLocale.size < 5) {
    throw new Error(`i18n.ts: parsed only ${byLocale.size} FACET_LABELS keys — format drift?`);
  }
  return byLocale;
}

let failed = false;
const fail = (msg) => {
  failed = true;
  console.error(`❌ ${msg}`);
};

try {
  const contentFields = parseSchemaFields(read('data/schema.yaml'), 'data/schema.yaml');
  const indexFields = parseSchemaFields(read('data/schema-index.yaml'), 'data/schema-index.yaml');
  const catalogKeys = parseFacetCatalog(read('src/Browse/FacetCatalog.php'));
  const labels = parseFacetLabels(read('src/svelte/lib/i18n.ts'));

  const facetIn = (name) => {
    const c = contentFields.get(name);
    const i = indexFields.get(name);
    if (!c && !i) return 'missing';
    return (c && c.facet) || (i && i.facet) ? 'facet' : 'not-facet';
  };

  for (const key of catalogKeys) {
    const status = facetIn(key);
    if (status === 'missing') {
      fail(`FacetCatalog key '${key}' is not declared in either schema YAML.`);
    } else if (status === 'not-facet') {
      fail(`FacetCatalog key '${key}' is in the schema but not facet: true.`);
    }
    const locales = labels.get(key);
    for (const locale of ['fr', 'en']) {
      if (!locales || !locales.has(locale)) {
        fail(`FacetCatalog key '${key}' has no '${locale}' label in i18n.ts FACET_LABELS.`);
      }
    }
  }

  // Informational: facet fields the admin picker deliberately doesn't offer.
  const offered = new Set(catalogKeys);
  const unoffered = [];
  for (const [name, meta] of [...contentFields, ...indexFields]) {
    if (meta.facet && !offered.has(name) && !unoffered.includes(name)) {
      unoffered.push(name);
    }
  }
  if (unoffered.length > 0) {
    console.log(
      `ℹ facet-enabled schema fields not offered in the admin catalog (deliberate): ${unoffered.join(', ')}`,
    );
  }

  if (!failed) {
    console.log(
      `✅ schema drift check: ${catalogKeys.length} catalog keys consistent across ` +
        'schema.yaml / schema-index.yaml / FacetCatalog.php / i18n.ts',
    );
  }
} catch (err) {
  fail(err instanceof Error ? err.message : String(err));
}

process.exit(failed ? 1 : 0);
