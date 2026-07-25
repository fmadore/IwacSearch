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

/**
 * Value of a PHP `public const NAME = '…'` string constant, with adjacent
 * `.`-concatenated parts joined. Used for the query_by / highlight field
 * lists in SearchDefaults.
 */
function parsePhpStringConst(phpText, name, label) {
  const re = new RegExp(`const\\s+${name}\\s*=\\s*([^;]+);`, 'm');
  const m = phpText.match(re);
  if (!m) {
    throw new Error(`${label}: const ${name} not found — update the drift check.`);
  }
  return [...m[1].matchAll(/'([^']*)'/g)].map((x) => x[1]).join('');
}

/**
 * Value of a TS `export const NAME = '…' + '…';` string constant.
 */
function parseTsStringConst(tsText, name, label) {
  const re = new RegExp(`const\\s+${name}\\s*=\\s*([^;]+);`, 'm');
  const m = tsText.match(re);
  if (!m) {
    throw new Error(`${label}: const ${name} not found — update the drift check.`);
  }
  return [...m[1].matchAll(/'([^']*)'/g)].map((x) => x[1]).join('');
}

/** Sort values from a PHP `const NAME = [ 'value' => 'Label', … ];` map. */
function parsePhpSortConst(phpText, name, label) {
  const start = phpText.indexOf(`${name} = [`);
  if (start === -1) {
    throw new Error(`${label}: const ${name} not found — update the drift check.`);
  }
  const body = phpText.slice(start, phpText.indexOf('];', start));
  const values = [...body.matchAll(/^\s*'([^']+)'\s*=>/gm)].map((m) => m[1]);
  if (values.length === 0) {
    throw new Error(`${label}: parsed no ${name} entries — format drift?`);
  }
  return values;
}

/**
 * Sort values the client offers per card, from i18n.ts `sortOptions()`. The
 * entity branch is the `if (card === 'entity')` block; content is the rest.
 */
function parseTsSortOptions(tsText, label) {
  const start = tsText.indexOf('export function sortOptions');
  if (start === -1) {
    throw new Error(`${label}: sortOptions() not found — update the drift check.`);
  }
  const body = tsText.slice(start, tsText.indexOf('\n}', start));
  const split = body.indexOf('return [', body.indexOf("card === 'entity'"));
  const entityEnd = body.indexOf('];', split);
  const grab = (text) => [...text.matchAll(/value:\s*'([^']+)'/g)].map((m) => m[1]);
  const entity = grab(body.slice(split, entityEnd));
  const content = grab(body.slice(entityEnd));
  if (entity.length === 0 || content.length === 0) {
    throw new Error(`${label}: parsed no sortOptions values — format drift?`);
  }
  return { entity, content };
}

let failed = false;
const fail = (msg) => {
  failed = true;
  console.error(`❌ ${msg}`);
};

const sameList = (a, b) => a.length === b.length && a.every((v, i) => v === b[i]);

try {
  const contentFields = parseSchemaFields(read('data/schema.yaml'), 'data/schema.yaml');
  const indexFields = parseSchemaFields(read('data/schema-index.yaml'), 'data/schema-index.yaml');
  const catalogKeys = parseFacetCatalog(read('src/Browse/FacetCatalog.php'));
  const labels = parseFacetLabels(read('src/svelte/lib/i18n.ts'));

  // PER-SCHEMA, not OR-ed across the two. A key is checked against every
  // schema that DECLARES it: `country_ss` and `pub_year` exist in both
  // collections, and OR-ing meant losing `facet: true` in one of them stayed
  // green while that surface's facet panel quietly broke. A key declared in
  // only one schema is only checked there — that is the normal case, not a
  // problem (ocr_text is content-only, entity_type_s is index-only).
  const schemas = [
    { label: 'data/schema.yaml', fields: contentFields },
    { label: 'data/schema-index.yaml', fields: indexFields },
  ];

  for (const key of catalogKeys) {
    const declaredIn = schemas.filter((s) => s.fields.has(key));

    if (declaredIn.length === 0) {
      fail(`FacetCatalog key '${key}' is not declared in either schema YAML.`);
    }
    for (const schema of declaredIn) {
      if (!schema.fields.get(key).facet) {
        fail(
          `FacetCatalog key '${key}' is declared in ${schema.label} but not facet: true there` +
            (declaredIn.length > 1
              ? ` (it IS a facet in the other schema — the panel on this collection's surface will break).`
              : '.'),
        );
      }
    }

    const locales = labels.get(key);
    for (const locale of ['fr', 'en']) {
      if (!locales || !locales.has(locale)) {
        fail(`FacetCatalog key '${key}' has no '${locale}' label in i18n.ts FACET_LABELS.`);
      }
    }
  }

  // Informational: which catalog keys live in both collections. These are the
  // ones the per-schema check above earns its keep on.
  const shared = catalogKeys.filter((k) => contentFields.has(k) && indexFields.has(k));
  if (shared.length > 0) {
    console.log(`ℹ catalog keys declared in BOTH schemas (checked per file): ${shared.join(', ')}`);
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

  // ── PHP ↔ TS search constants ────────────────────────────────────────
  // Same failure class as the facet catalog: two hand-maintained copies of
  // one contract.
  //
  //   query_by / highlight_fields — the PHP list drives the SSR search and
  //     (via the bootstrap) the client; the TS list is the fallback the
  //     client uses when a bootstrap omits them. Divergence means the
  //     server-rendered first page and the client's own searches read
  //     different fields on the same surface.
  //
  //   sort options — the block form offers the PHP list; SortSelect renders
  //     the TS one. Divergence means an admin can pick an order the client
  //     can't display (the <select> silently shows a different option than
  //     the one actually applied).
  const defaults = read('src/Search/SearchDefaults.php');
  const builders = read('src/svelte/lib/queryBuilders.ts');
  const fieldPairs = [
    ['CONTENT_QUERY_BY', 'CONTENT_QUERY_BY_FALLBACK'],
    ['CONTENT_HIGHLIGHT_FIELDS', 'CONTENT_HIGHLIGHT_FALLBACK'],
  ];
  for (const [phpName, tsName] of fieldPairs) {
    const php = parsePhpStringConst(defaults, phpName, 'SearchDefaults.php');
    const ts = parseTsStringConst(builders, tsName, 'queryBuilders.ts');
    if (php !== ts) {
      fail(
        `SearchDefaults::${phpName} and queryBuilders.ts ${tsName} have drifted.\n` +
          `      PHP: ${php}\n       TS: ${ts}`,
      );
    }
  }

  const facetCatalogPhp = read('src/Browse/FacetCatalog.php');
  const i18n = read('src/svelte/lib/i18n.ts');
  const tsSorts = parseTsSortOptions(i18n, 'i18n.ts');
  const sortPairs = [
    ['SORT_OPTIONS', tsSorts.content, 'content'],
    ['SORT_OPTIONS_ENTITY', tsSorts.entity, 'entity'],
  ];
  for (const [phpName, tsValues, card] of sortPairs) {
    const php = parsePhpSortConst(facetCatalogPhp, phpName, 'FacetCatalog.php');
    if (!sameList(php, tsValues)) {
      fail(
        `FacetCatalog::${phpName} and i18n.ts sortOptions(card='${card}') have drifted.\n` +
          `      PHP: ${php.join(', ')}\n       TS: ${tsValues.join(', ')}`,
      );
    }
  }

  if (!failed) {
    console.log(
      `✅ schema drift check: ${catalogKeys.length} catalog keys consistent across ` +
        'schema.yaml / schema-index.yaml / FacetCatalog.php / i18n.ts; ' +
        'query_by, highlight and sort constants consistent across PHP / TS',
    );
  }
} catch (err) {
  fail(err instanceof Error ? err.message : String(err));
}

process.exit(failed ? 1 : 0);
